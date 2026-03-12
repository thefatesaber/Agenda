// ── State ────────────────────────────────────────────────────────────────────
const calendarEl  = document.getElementById('calendar');
const monthYearEl = document.getElementById('monthYear');
const modalEl     = document.getElementById('eventModal');

let currentDate   = new Date();
let currentView   = 'monthly';
let filterQuery   = '';
let collapseHours = false;
let dragState     = null;
let wasDrag       = false;
let swipeStartX   = null;
let resizeState   = null;
let lastDragOp    = null;

// New state (#86 focus mode, #13 pinch zoom, #2 drag-create, #44 recent searches)
let focusModeActive = false;
let pinchStartDist  = null;
let pinchBaseScale  = 1;
let dragCreateState = null;
let recentSearches  = [];
let hiddenCalendars = new Set();
let activeFilters   = {};
let pomodoroInterval = null;
let pomodoroTimeLeft = 25 * 60;
let pomodoroRunning  = false;
let pomodoroSession  = 'work';
let pomodoroCount    = 0;
let notifHistory     = [];
let duplicateEventId = null;
let moveEventId      = null;

// Bulk select state
let bulkSelectActive = false;
let selectedEventIds = new Set();

// Notifications
let notifiedEvents = new Set();

// Fetch range tracking (for lazy loading)
let fetchedRanges = [];

// Context menu
let contextEventData = null;

// Month picker
let pickerYear = new Date().getFullYear();

// Settings
function getSetting(key, def) {
    return localStorage.getItem('cal_' + key) || def;
}
function saveSetting(key, val) {
    localStorage.setItem('cal_' + key, val);
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function padZ(n) { return String(n).padStart(2, '0'); }

function toDateStr(d) {
    return d.getFullYear() + '-' + padZ(d.getMonth() + 1) + '-' + padZ(d.getDate());
}

function timeToMinutes(timeStr) {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
}

function minsToTimeStr(mins) {
    return padZ(Math.floor(mins / 60) % 24) + ':' + padZ(mins % 60);
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const fmt = getSetting('clockFmt', '24');
    const parts = timeStr.split(':');
    const h = parseInt(parts[0], 10);
    const m = parts[1] || '00';
    if (fmt === '12') {
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12  = h % 12 || 12;
        return h12 + ':' + m + ' ' + ampm;
    }
    return padZ(h) + ':' + m;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const fmt = getSetting('dateFmt', 'MDY');
    const parts = dateStr.split('-');
    if (parts.length < 3) return dateStr;
    const y = parts[0], m = parts[1], d = parts[2];
    if (fmt === 'DMY') return d + '/' + m + '/' + y;
    return m + '/' + d + '/' + y;
}

function getISOWeek(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNum = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
}

function visibleEvents() {
    let pool = events;

    // Filter by hidden calendars (#52)
    if (hiddenCalendars.size > 0) {
        pool = pool.filter(function(e) {
            return !hiddenCalendars.has(String(e.calendar_id));
        });
    }

    // Focus mode: hide low priority past events (#86)
    if (focusModeActive) {
        const td = todayStr();
        pool = pool.filter(function(e) {
            if (e.date < td && (e.priority || 1) === 1) return false;
            return true;
        });
    }

    // Panel filters (#41)
    const logic = (activeFilters.logic || 'AND').toUpperCase();
    const filterFns = [];
    if (activeFilters.category) {
        const fc = activeFilters.category.toLowerCase();
        filterFns.push(function(e) { return (e.category || '').toLowerCase().includes(fc); });
    }
    if (activeFilters.priority) {
        const fp = String(activeFilters.priority);
        filterFns.push(function(e) { return String(e.priority || 1) === fp; });
    }
    if (activeFilters.color && activeFilters.color !== '#000000') {
        filterFns.push(function(e) { return (e.color || '').toLowerCase() === activeFilters.color.toLowerCase(); });
    }
    if (activeFilters.tag) {
        const ft = activeFilters.tag.toLowerCase();
        filterFns.push(function(e) { return (e.tags || '').toLowerCase().includes(ft); });
    }
    if (activeFilters.attendee) {
        const fa = activeFilters.attendee.toLowerCase();
        filterFns.push(function(e) { return (e.attendees || '').toLowerCase().includes(fa); });
    }
    if (activeFilters.location) {
        const fl = activeFilters.location.toLowerCase();
        filterFns.push(function(e) { return (e.location || '').toLowerCase().includes(fl); });
    }
    if (activeFilters.dateFrom) {
        filterFns.push(function(e) { return e.date >= activeFilters.dateFrom; });
    }
    if (activeFilters.dateTo) {
        filterFns.push(function(e) { return e.date <= activeFilters.dateTo; });
    }
    if (filterFns.length > 0) {
        if (logic === 'OR') {
            pool = pool.filter(function(e) { return filterFns.some(function(fn) { return fn(e); }); });
        } else {
            pool = pool.filter(function(e) { return filterFns.every(function(fn) { return fn(e); }); });
        }
    }

    // Text search (#42 full-text including notes)
    if (filterQuery) {
        const q = filterQuery.toLowerCase();
        pool = pool.filter(function(e) {
            return (e.title || '').toLowerCase().includes(q) ||
                   (e.category || '').toLowerCase().includes(q) ||
                   (e.notes || '').toLowerCase().includes(q) ||
                   (e.location || '').toLowerCase().includes(q) ||
                   (e.tags || '').toLowerCase().includes(q) ||
                   (e.attendees || '').toLowerCase().includes(q);
        });
    }

    return pool;
}

function getHourRange() {
    if (!collapseHours) return { start: 0, end: 24 };
    const vis = visibleEvents();
    if (!vis.length) return { start: 7, end: 20 };
    let minH = 23, maxH = 0;
    vis.forEach(function (e) {
        if (e.start_time) {
            const h = parseInt(e.start_time.split(':')[0], 10);
            if (h < minH) minH = h;
        }
        if (e.end_time) {
            const h = parseInt(e.end_time.split(':')[0], 10) + 1;
            if (h > maxH) maxH = h;
        }
    });
    return { start: Math.max(0, minH - 1), end: Math.min(24, maxH + 1) };
}

function todayStr() {
    return toDateStr(new Date());
}

function nowMins() {
    const now = new Date();
    return now.getHours() * 60 + now.getMinutes();
}

// ── Overlap layout ────────────────────────────────────────────────────────────
function layoutOverlappingEvents(dayEvents) {
    const sorted = dayEvents.slice().sort(function (a, b) {
        const sa = timeToMinutes(a.start_time), sb = timeToMinutes(b.start_time);
        return sa !== sb ? sa - sb : (timeToMinutes(b.end_time) - timeToMinutes(a.end_time));
    });
    const colEnds = [];
    const assignments = sorted.map(function (event) {
        const start = timeToMinutes(event.start_time);
        const end   = timeToMinutes(event.end_time) || start + 60;
        let col = colEnds.findIndex(function (e) { return e <= start; });
        if (col === -1) { col = colEnds.length; colEnds.push(end); }
        else            { colEnds[col] = end; }
        return { event: event, col: col, start: start, end: end };
    });
    return assignments.map(function (item) {
        var maxCol = item.col;
        assignments.forEach(function (other) {
            if (item.start < other.end && item.end > other.start && other.col > maxCol) {
                maxCol = other.col;
            }
        });
        return { event: item.event, col: item.col, totalCols: maxCol + 1 };
    });
}

// ── Priority dot ─────────────────────────────────────────────────────────────
function createPriorityDot(priority) {
    const dot = document.createElement('div');
    dot.className = 'priority-dot';
    const colors = { 1: '#22c55e', 2: '#f97316', 3: '#ef4444' };
    dot.style.background = colors[priority] || colors[1];
    return dot;
}

// ── Overdue check ────────────────────────────────────────────────────────────
function isOverdue(event) {
    const td = todayStr();
    if (event.date < td) return true;
    if (event.date === td && event.end_time && timeToMinutes(event.end_time) < nowMins()) return true;
    return false;
}

// ── Timed event element ───────────────────────────────────────────────────────

// ── Build time labels ─────────────────────────────────────────────────────────
function buildTimeLabels(hourRange) {
    const range = hourRange || { start: 0, end: 24 };
    const col   = document.createElement('div');
    col.className = 'time-labels-col';
    for (let h = range.start; h < range.end; h++) {
        const label = document.createElement('div');
        label.className = 'hour-label';
        label.textContent = formatTime(padZ(h) + ':00');
        col.appendChild(label);
    }
    return col;
}

// ── Build day events column ───────────────────────────────────────────────────

// ── Monthly day cell ──────────────────────────────────────────────────────────

// ── Render functions ──────────────────────────────────────────────────────────


function renderWeekly(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = 'time-view';

    const today       = new Date();
    const firstDay    = parseInt(getSetting('firstDay', '0'), 10);
    const startOfWeek = new Date(date);
    const dow         = date.getDay();
    const diff        = (dow - firstDay + 7) % 7;
    startOfWeek.setDate(date.getDate() - diff);
    const endOfWeek = new Date(startOfWeek);
    endOfWeek.setDate(startOfWeek.getDate() + 6);

    const startStr = startOfWeek.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const endStr   = endOfWeek.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    monthYearEl.textContent = startStr + ' \u2013 ' + endStr;

    const weekDays  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const container = document.createElement('div');
    container.className = 'time-grid-container';

    const header  = document.createElement('div');
    header.className = 'time-grid-header';
    const gutterH = document.createElement('div');
    gutterH.className = 'time-gutter';
    header.appendChild(gutterH);

    // Week number
    const wkCol = document.createElement('div');
    wkCol.className = 'week-number-col';
    wkCol.textContent = 'W' + getISOWeek(startOfWeek);
    header.appendChild(wkCol);

    for (let i = 0; i < 7; i++) {
        const d = new Date(startOfWeek);
        d.setDate(startOfWeek.getDate() + i);
        const ds      = toDateStr(d);
        const isToday = ds === toDateStr(today);

        const headerCol = document.createElement('div');
        headerCol.className = 'day-header-col' + (isToday ? ' today-header' : '');

        const span = document.createElement('span');
        span.textContent = weekDays[d.getDay()] + ' ' + d.getDate();

        const addBtn = document.createElement('button');
        addBtn.className  = 'overlay-btn time-add-btn';
        addBtn.textContent = '+';
        addBtn.onclick = (function (dateStr) {
            return function (e) { e.stopPropagation(); openModalForAdd(dateStr); };
        })(ds);

        headerCol.appendChild(span);
        headerCol.appendChild(addBtn);
        header.appendChild(headerCol);
    }
    container.appendChild(header);

    const range = getHourRange();
    const body  = document.createElement('div');
    body.className = 'time-grid-body';

    // Week number gutter
    const wkGutter = document.createElement('div');
    wkGutter.className = 'week-number-col week-number-body';
    body.appendChild(wkGutter);

    body.appendChild(buildTimeLabels(range));

    const rangeStart = toDateStr(startOfWeek);
    const rangeEnd   = toDateStr(endOfWeek);

    for (let i = 0; i < 7; i++) {
        const d = new Date(startOfWeek);
        d.setDate(startOfWeek.getDate() + i);
        const ds      = toDateStr(d);
        const isToday = ds === toDateStr(today);
        body.appendChild(buildDayEventsCol(ds, isToday, range));
    }

    container.appendChild(body);
    calendarEl.appendChild(container);
    updateTimeIndicator(body, container);
    body.scrollTop = Math.max(0, (8 - range.start) * 60);

    fetchRange(rangeStart, rangeEnd);
}

function renderBiweekly(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = 'time-view';

    const today       = new Date();
    const firstDay    = parseInt(getSetting('firstDay', '0'), 10);
    const startOfWeek = new Date(date);
    const dow         = date.getDay();
    const diff        = (dow - firstDay + 7) % 7;
    startOfWeek.setDate(date.getDate() - diff);
    const endDate = new Date(startOfWeek);
    endDate.setDate(startOfWeek.getDate() + 13);

    const startStr = startOfWeek.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const endStr   = endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    monthYearEl.textContent = startStr + ' \u2013 ' + endStr;

    const weekDays  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const container = document.createElement('div');
    container.className = 'time-grid-container';

    const header  = document.createElement('div');
    header.className = 'time-grid-header';
    const gutterH = document.createElement('div');
    gutterH.className = 'time-gutter';
    header.appendChild(gutterH);

    // Week number
    const wkCol = document.createElement('div');
    wkCol.className = 'week-number-col';
    wkCol.textContent = 'W' + getISOWeek(startOfWeek);
    header.appendChild(wkCol);

    for (let i = 0; i < 14; i++) {
        const d = new Date(startOfWeek);
        d.setDate(startOfWeek.getDate() + i);
        const ds      = toDateStr(d);
        const isToday = ds === toDateStr(today);

        const headerCol = document.createElement('div');
        headerCol.className = 'day-header-col' + (isToday ? ' today-header' : '');

        const span = document.createElement('span');
        span.textContent = weekDays[d.getDay()] + ' ' + d.getDate();

        const addBtn = document.createElement('button');
        addBtn.className  = 'overlay-btn time-add-btn';
        addBtn.textContent = '+';
        addBtn.onclick = (function (dateStr) {
            return function (e) { e.stopPropagation(); openModalForAdd(dateStr); };
        })(ds);

        headerCol.appendChild(span);
        headerCol.appendChild(addBtn);
        header.appendChild(headerCol);
    }
    container.appendChild(header);

    const range = getHourRange();
    const body  = document.createElement('div');
    body.className = 'time-grid-body';

    const wkGutter = document.createElement('div');
    wkGutter.className = 'week-number-col week-number-body';
    body.appendChild(wkGutter);

    body.appendChild(buildTimeLabels(range));

    const rangeStart = toDateStr(startOfWeek);
    const rangeEnd   = toDateStr(endDate);

    for (let i = 0; i < 14; i++) {
        const d = new Date(startOfWeek);
        d.setDate(startOfWeek.getDate() + i);
        const ds      = toDateStr(d);
        const isToday = ds === toDateStr(today);
        body.appendChild(buildDayEventsCol(ds, isToday, range));
    }

    container.appendChild(body);
    calendarEl.appendChild(container);
    updateTimeIndicator(body, container);
    body.scrollTop = Math.max(0, (8 - range.start) * 60);

    fetchRange(rangeStart, rangeEnd);
}

function renderDaily(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = 'time-view';

    const ds = toDateStr(date);
    monthYearEl.textContent = date.toLocaleDateString('en-US', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    });

    const container = document.createElement('div');
    container.className = 'time-grid-container';

    const header  = document.createElement('div');
    header.className = 'time-grid-header';
    const gutterH = document.createElement('div');
    gutterH.className = 'time-gutter';
    header.appendChild(gutterH);

    const headerCol = document.createElement('div');
    headerCol.className = 'day-header-col';
    const span = document.createElement('span');
    span.textContent = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    const addBtn = document.createElement('button');
    addBtn.className  = 'overlay-btn time-add-btn';
    addBtn.textContent = '+ Add';
    addBtn.onclick = function () { openModalForAdd(ds); };
    headerCol.appendChild(span);
    headerCol.appendChild(addBtn);
    header.appendChild(headerCol);
    container.appendChild(header);

    const range = getHourRange();
    const body  = document.createElement('div');
    body.className = 'time-grid-body';
    body.appendChild(buildTimeLabels(range));
    body.appendChild(buildDayEventsCol(ds, false, range));

    container.appendChild(body);
    calendarEl.appendChild(container);
    updateTimeIndicator(body, container);
    body.scrollTop = Math.max(0, (8 - range.start) * 60);

    fetchRange(ds, ds);
}


function renderList(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = '';

    const rangeDaysEl = document.getElementById('listRangeDays');
    const rangeDays = rangeDaysEl ? parseInt(rangeDaysEl.value, 10) : 30;

    const startDate = new Date(date);
    startDate.setHours(0, 0, 0, 0);
    const endDate = new Date(startDate);
    endDate.setDate(startDate.getDate() + rangeDays - 1);

    const startStr = toDateStr(startDate);
    const endStr   = toDateStr(endDate);

    monthYearEl.textContent = 'List: ' + formatDate(startStr) + ' \u2013 ' + formatDate(endStr);

    const container = document.createElement('div');
    container.className = 'list-view';

    const filtered = visibleEvents().filter(function (e) {
        return e.date >= startStr && e.date <= endStr;
    }).sort(function (a, b) {
        if (a.date !== b.date) return a.date < b.date ? -1 : 1;
        return timeToMinutes(a.start_time) - timeToMinutes(b.start_time);
    });

    if (filtered.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'no-events';
        empty.textContent = 'No events in this range.';
        container.appendChild(empty);
    } else {
        const table = document.createElement('table');
        table.className = 'list-table';
        const thead = document.createElement('thead');
        const hRow  = document.createElement('tr');
        ['Date', 'Time', 'Course', 'Instructor', 'Category', 'Priority'].forEach(function (h) {
            const th = document.createElement('th');
            th.textContent = h;
            hRow.appendChild(th);
        });
        thead.appendChild(hRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        filtered.forEach(function (event) {
            const tr = document.createElement('tr');
            tr.className = 'list-event-row';
            if (isOverdue(event)) tr.classList.add('event-overdue');

            const parts = event.title.split(' - ');
            const priLabels = {1: 'Low', 2: 'Medium', 3: 'High'};
            [
                formatDate(event.date),
                formatTime(event.start_time) + ' \u2013 ' + formatTime(event.end_time),
                parts[0] || '',
                parts[1] || '',
                event.category || '',
                priLabels[event.priority] || 'Low',
            ].forEach(function (val) {
                const td = document.createElement('td');
                td.textContent = val;
                tr.appendChild(td);
            });

            tr.onclick = function () { openModalForEdit([event]); };
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        container.appendChild(table);
    }

    calendarEl.appendChild(container);
    fetchRange(startStr, endStr);
}

// ── AJAX lazy loading ─────────────────────────────────────────────────────────
function rangeIsFetched(start, end) {
    return fetchedRanges.some(function (r) {
        return r.start <= start && r.end >= end;
    });
}

function fetchRange(start, end) {
    if (rangeIsFetched(start, end)) return;

    const body = new URLSearchParams();
    body.set('action', 'fetch_events');
    body.set('start', start);
    body.set('end', end);

    fetch('ajax.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok && data.events) {
                const existingKeys = new Set(events.map(function (e) { return e.id + '_' + e.date; }));
                data.events.forEach(function (e) {
                    const key = e.id + '_' + e.date;
                    if (!existingKeys.has(key)) {
                        events.push(e);
                        existingKeys.add(key);
                    }
                });
                fetchedRanges.push({ start: start, end: end });
                renderCalendar(currentDate);
            }
        })
        .catch(function () {});
}

// ── Time indicator ────────────────────────────────────────────────────────────

// ── View switching ────────────────────────────────────────────────────────────


function goToToday() {
    currentDate = new Date();
    renderCalendar(currentDate);
}

// ── Filter & collapse ─────────────────────────────────────────────────────────

function toggleCollapseHours() {
    collapseHours = !collapseHours;
    const btn = document.getElementById('collapseHoursBtn');
    if (btn) btn.textContent = collapseHours ? 'Show All Hours' : 'Collapse Hours';
    renderCalendar(currentDate);
}

// ── Tooltip ───────────────────────────────────────────────────────────────────
let tooltipEl = null;

function showTooltip(event, e) {
    hideTooltip();
    tooltipEl = document.createElement('div');
    tooltipEl.className = 'event-tooltip';

    const title = document.createElement('div');
    title.className  = 'tooltip-title';
    title.textContent = event.title;
    tooltipEl.appendChild(title);

    function addRow(label, val) {
        if (!val) return;
        const row = document.createElement('div');
        row.className  = 'tooltip-row';
        row.textContent = label + ': ' + val;
        tooltipEl.appendChild(row);
    }

    addRow('Time', formatTime(event.start_time) + ' \u2013 ' + formatTime(event.end_time));
    if (event.category) addRow('Category', event.category);
    if (event.notes)    addRow('Notes', event.notes);
    if (event.recurrence && event.recurrence !== 'none') addRow('Repeat', event.recurrence);
    const priLabels = {1:'Low', 2:'Medium', 3:'High'};
    addRow('Priority', priLabels[event.priority] || 'Low');

    // Attachment link
    if (event.attachment) {
        const attRow = document.createElement('div');
        attRow.className = 'tooltip-row';
        const attA = document.createElement('a');
        attA.href = event.attachment;
        attA.target = '_blank';
        attA.textContent = '\uD83D\uDCCE Attachment';
        attA.style.color = '#93c5fd';
        attRow.appendChild(attA);
        tooltipEl.appendChild(attRow);
    }

    // URL link
    if (event.event_url) {
        const urlRow = document.createElement('div');
        urlRow.className = 'tooltip-row';
        const urlA = document.createElement('a');
        urlA.href = event.event_url;
        urlA.target = '_blank';
        urlA.rel = 'noopener noreferrer';
        urlA.textContent = '\uD83D\uDD17 Link';
        urlA.style.color = '#93c5fd';
        urlRow.appendChild(urlA);
        tooltipEl.appendChild(urlRow);
    }

    tooltipEl.style.position = 'fixed';
    tooltipEl.style.left     = (e.clientX + 12) + 'px';
    tooltipEl.style.top      = (e.clientY + 12) + 'px';
    tooltipEl.style.zIndex   = '99999';
    document.body.appendChild(tooltipEl);
}

function hideTooltip() {
    if (tooltipEl) { tooltipEl.remove(); tooltipEl = null; }
}

// ── Confirm modal ─────────────────────────────────────────────────────────────
function showConfirmModal(msg, onConfirm) {
    const modal  = document.getElementById('confirmModal');
    const msgEl  = document.getElementById('confirmMessage');
    const yesBtn = document.getElementById('confirmYesBtn');
    if (!modal || !msgEl || !yesBtn) return;
    msgEl.textContent = msg;
    yesBtn.onclick = function () { hideConfirmModal(); onConfirm(); };
    modal.style.display = 'flex';
}

function hideConfirmModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) modal.style.display = 'none';
}

function confirmDeleteEvent() {
    showConfirmModal('Are you sure you want to delete this event?', function () {
        const form = document.getElementById('deleteForm');
        if (form) form.submit();
    });
}

function confirmDeleteAll() {
    showConfirmModal('Delete ALL events? This cannot be undone.', function () {
        const form = document.getElementById('deleteAllForm');
        if (form) form.submit();
    });
}

// ── Spinner ───────────────────────────────────────────────────────────────────
function showSpinner() {
    const overlay = document.getElementById('spinnerOverlay');
    if (overlay) overlay.style.display = 'flex';
}

function hideSpinner() {
    const overlay = document.getElementById('spinnerOverlay');
    if (overlay) overlay.style.display = 'none';
}

// ── Duplicate ─────────────────────────────────────────────────────────────────
function duplicateEvent() {
    const id = document.getElementById('eventID').value;
    if (!id) return;
    showSpinner();
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    const a1 = document.createElement('input'); a1.name = 'action';   a1.value = 'duplicate'; form.appendChild(a1);
    const a2 = document.createElement('input'); a2.name = 'event_id'; a2.value = id;           form.appendChild(a2);
    document.body.appendChild(form);
    form.submit();
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function toggleRecurrenceEnd(value) {
    document.getElementById('recurrenceEndWrapper').style.display = value !== 'none' ? 'block' : 'none';
}

function resetSubmitButtons() {
    document.querySelectorAll('#eventModal button').forEach(function (btn) { btn.disabled = false; });
}


function openModalForEdit(eventsOnDate) {
    resetSubmitButtons();
    document.getElementById('formAction').value = 'edit';
    modalEl.style.display = 'flex';

    const dupBtn = document.getElementById('duplicateBtn');
    if (dupBtn) dupBtn.style.display = 'block';

    const selector = document.getElementById('eventSelector');
    const wrapper  = document.getElementById('eventSelectorWrapper');

    selector.innerHTML = '<option disabled selected>Choose event...</option>';
    eventsOnDate.forEach(function (e) {
        const option = document.createElement('option');
        option.value       = JSON.stringify(e);
        option.textContent = e.title.split(' - ')[0] + ' (' + e.start + ' \u2192 ' + e.end + ')';
        selector.appendChild(option);
    });
    wrapper.style.display = eventsOnDate.length > 1 ? 'block' : 'none';

    loadTemplateOptions();
    handleEventSelection(JSON.stringify(eventsOnDate[0]));
}


function closeModal() {
    modalEl.style.display = 'none';
    hideTooltip();
}

// ── Conflict detection (client-side) ─────────────────────────────────────────
function checkConflicts() {
    const warn = document.getElementById('conflictWarning');
    if (!warn) return;

    const id        = document.getElementById('eventID').value;
    const startDate = document.getElementById('startDate').value;
    const endDate   = document.getElementById('endDate').value;
    const startTime = document.getElementById('startTime').value;
    const endTime   = document.getElementById('endTime').value;

    if (!startDate || !endDate || !startTime || !endTime) { warn.style.display = 'none'; return; }

    const newStart = timeToMinutes(startTime);
    const newEnd   = timeToMinutes(endTime);

    const conflict = events.some(function (e) {
        if (String(e.id) === String(id)) return false;
        if (e.date < startDate || e.date > endDate) return false;
        const eStart = timeToMinutes(e.start_time);
        const eEnd   = timeToMinutes(e.end_time);
        return eStart < newEnd && eEnd > newStart;
    });

    warn.style.display = conflict ? 'block' : 'none';
}

// Add change listeners for conflict detection
['startDate','endDate','startTime','endTime'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', checkConflicts);
});

// ── Clock ─────────────────────────────────────────────────────────────────────
function updateClock() {
    const now   = new Date();
    const clock = document.getElementById('clock');
    const fmt   = getSetting('clockFmt', '24');
    if (clock) {
        if (fmt === '12') {
            const h    = now.getHours();
            const m    = padZ(now.getMinutes());
            const s    = padZ(now.getSeconds());
            const ampm = h >= 12 ? 'PM' : 'AM';
            clock.textContent = (h % 12 || 12) + ':' + m + ':' + s + ' ' + ampm;
        } else {
            clock.textContent = padZ(now.getHours()) + ':' + padZ(now.getMinutes()) + ':' + padZ(now.getSeconds());
        }
    }
}

// ── Dark mode ─────────────────────────────────────────────────────────────────
function toggleDark() {
    const isDark = document.body.classList.toggle('dark');
    localStorage.setItem('darkMode', isDark ? '1' : '0');
    const btn = document.getElementById('darkToggle');
    if (btn) btn.textContent = isDark ? 'Light Mode' : 'Dark Mode';
}

// ── Drag & drop ───────────────────────────────────────────────────────────────
document.addEventListener('mousemove', function (e) {
    // Resize
    if (resizeState) {
        const dy     = e.clientY - resizeState.startY;
        const newH   = Math.max(20, resizeState.origHeight + dy);
        resizeState.el.style.height = newH + 'px';

        // Snap line in hovered column
        const col = resizeState.el.closest('.day-events-col');
        if (col) {
            let snapLine = col.querySelector('.drag-snap-line');
            if (!snapLine) {
                snapLine = document.createElement('div');
                snapLine.className = 'drag-snap-line';
                col.appendChild(snapLine);
            }
            const range      = resizeState.range;
            const endMinsRaw = resizeState.origEndMins + dy;
            const snapped    = Math.round(endMinsRaw / 15) * 15;
            snapLine.style.top = (snapped - range.start * 60) + 'px';
        }
        return;
    }

    if (!dragState) return;
    const dx = e.clientX - dragState.startX;
    const dy = e.clientY - dragState.startY;

    if (!wasDrag && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) {
        wasDrag = true;
        const rect = dragState.el.getBoundingClientRect();
        dragState.fixedLeft  = rect.left;
        dragState.fixedTop   = rect.top;
        dragState.fixedWidth = rect.width;
        const el = dragState.el;
        el.style.position = 'fixed';
        el.style.left     = rect.left  + 'px';
        el.style.top      = rect.top   + 'px';
        el.style.width    = rect.width + 'px';
        el.style.right    = 'auto';
        el.style.zIndex   = '9999';
    }

    if (!wasDrag) return;

    dragState.el.style.left = (dragState.fixedLeft + dx) + 'px';
    dragState.el.style.top  = (dragState.fixedTop  + dy) + 'px';

    // Highlight column under cursor + snap line
    document.querySelectorAll('.day-events-col').forEach(function (c) { c.classList.remove('drag-over'); });
    // Remove snap lines from all cols
    document.querySelectorAll('.drag-snap-line').forEach(function (l) { l.remove(); });

    dragState.el.style.display = 'none';
    const under = document.elementFromPoint(e.clientX, e.clientY);
    dragState.el.style.display = '';
    const hoverCol = under ? under.closest('.day-events-col') : null;
    if (hoverCol) {
        hoverCol.classList.add('drag-over');

        // Snap indicator
        const colRect  = hoverCol.getBoundingClientRect();
        const range    = getHourRange();
        const relY     = e.clientY - colRect.top + hoverCol.scrollTop;
        const rawMins  = relY + range.start * 60;
        const snapped  = Math.round(rawMins / 15) * 15;
        const snapLine = document.createElement('div');
        snapLine.className  = 'drag-snap-line';
        snapLine.style.top  = (snapped - range.start * 60) + 'px';
        hoverCol.appendChild(snapLine);
    }
});

document.addEventListener('mouseup', function (e) {
    // Resize end
    if (resizeState) {
        const state = resizeState;
        resizeState = null;
        state.el.style.cursor = 'pointer';

        // Remove snap line
        document.querySelectorAll('.drag-snap-line').forEach(function (l) { l.remove(); });

        const dy = e.clientY - state.startY;
        const rawEndMins = state.origEndMins + dy;
        const newEnd     = Math.min(24 * 60, Math.max(state.origEvent.start_time ? timeToMinutes(state.origEvent.start_time) + 15 : 15, Math.round(rawEndMins / 15) * 15));
        const newDate    = state.origEvent.date;
        const newStart   = state.origEvent.start_time || '00:00';

        showSpinner();
        const body = new URLSearchParams();
        body.set('action',     'update_time');
        body.set('event_id',   state.eventId);
        body.set('start_date', newDate);
        body.set('end_date',   newDate);
        body.set('start_time', newStart);
        body.set('end_time',   minsToTimeStr(newEnd));

        fetch('ajax.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                hideSpinner();
                if (data.ok) {
                    lastDragOp = {
                        eventId:  state.eventId,
                        date:     newDate,
                        start_time: newStart,
                        end_time:  minsToTimeStr(newEnd),
                        origDate:  state.origEvent.date,
                        origStart: state.origEvent.start_time,
                        origEnd:   state.origEvent.end_time,
                    };
                    events.forEach(function (ev) {
                        if (String(ev.id) === String(state.eventId) && ev.date === state.origEvent.date) {
                            ev.end_time = minsToTimeStr(newEnd);
                        }
                    });
                    renderCalendar(currentDate);
                }
            })
            .catch(function () { hideSpinner(); });
        return;
    }

    if (!dragState) return;
    const state = dragState;
    dragState = null;

    state.el.style.opacity  = '1';
    state.el.style.cursor   = 'pointer';
    state.el.style.position = '';
    state.el.style.left     = '';
    state.el.style.width    = '';
    state.el.style.zIndex   = '';
    document.querySelectorAll('.day-events-col').forEach(function (c) { c.classList.remove('drag-over'); });
    document.querySelectorAll('.drag-snap-line').forEach(function (l) { l.remove(); });

    if (!wasDrag) return;

    state.el.style.display = 'none';
    const elemUnder = document.elementFromPoint(e.clientX, e.clientY);
    state.el.style.display = '';
    const targetCol = elemUnder ? elemUnder.closest('[data-date]') : null;
    const newDate   = targetCol ? targetCol.dataset.date : state.origEvent.date;

    const dy        = e.clientY - state.startY;
    const deltaMins = Math.round(dy / 15) * 15;
    const newStart  = Math.max(0, Math.min(23 * 60, state.startMins + deltaMins));
    const duration  = timeToMinutes(state.origEvent.end_time || '00:00') - state.startMins;
    const newEnd    = Math.min(24 * 60, newStart + Math.max(15, duration));

    showSpinner();

    const body = new URLSearchParams();
    body.set('action',     'update_time');
    body.set('event_id',   state.eventId);
    body.set('start_date', newDate);
    body.set('end_date',   newDate);
    body.set('start_time', minsToTimeStr(newStart));
    body.set('end_time',   minsToTimeStr(newEnd));

    fetch('ajax.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            hideSpinner();
            if (data.ok) {
                lastDragOp = {
                    eventId:   state.eventId,
                    date:      newDate,
                    start_time: minsToTimeStr(newStart),
                    end_time:   minsToTimeStr(newEnd),
                    origDate:  state.origEvent.date,
                    origStart: state.origEvent.start_time,
                    origEnd:   state.origEvent.end_time,
                };
                events.forEach(function (ev) {
                    if (String(ev.id) === String(state.eventId) &&
                        ev.date === state.origEvent.date) {
                        ev.date       = newDate;
                        ev.start      = newDate;
                        ev.end        = newDate;
                        ev.start_time = minsToTimeStr(newStart);
                        ev.end_time   = minsToTimeStr(newEnd);
                    }
                });
                renderCalendar(currentDate);
            }
        })
        .catch(function () { hideSpinner(); });
});

// ── Form submit interceptor ───────────────────────────────────────────────────
const eventFormEl = document.getElementById('eventForm');
if (eventFormEl) {
    eventFormEl.addEventListener('submit', function () {
        const scope = document.querySelector('input[name="edit_scope"]:checked');
        if (scope && scope.value === 'this') {
            document.getElementById('formAction').value = 'edit_occurrence';
        }
        showSpinner();
        const btn = this.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
    });
}

document.querySelectorAll('#eventModal form').forEach(function (form) {
    if (form.id === 'eventForm') return;
    form.addEventListener('submit', function () {
        this.querySelectorAll('button[type="submit"]').forEach(function (btn) { btn.disabled = true; });
    });
});

// ── Keyboard navigation ───────────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

    // Ctrl+Z undo drag/resize
    if (e.ctrlKey && e.key === 'z' && lastDragOp) {
        e.preventDefault();
        const op = lastDragOp;
        lastDragOp = null;

        showSpinner();
        const body = new URLSearchParams();
        body.set('action',     'update_time');
        body.set('event_id',   op.eventId);
        body.set('start_date', op.origDate);
        body.set('end_date',   op.origDate);
        body.set('start_time', op.origStart);
        body.set('end_time',   op.origEnd);

        fetch('ajax.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                hideSpinner();
                if (data.ok) {
                    events.forEach(function (ev) {
                        if (String(ev.id) === String(op.eventId) && ev.date === op.date) {
                            ev.date       = op.origDate;
                            ev.start      = op.origDate;
                            ev.end        = op.origDate;
                            ev.start_time = op.origStart;
                            ev.end_time   = op.origEnd;
                        }
                    });
                    renderCalendar(currentDate);
                }
            })
            .catch(function () { hideSpinner(); });
        return;
    }

    if (e.key === 'ArrowLeft')       changeMonth(-1);
    else if (e.key === 'ArrowRight') changeMonth(1);
    else if (e.key === 't' || e.key === 'T') goToToday();
    else if (e.key === 'Escape') {
        closeModal();
        hideConfirmModal();
        hideContextMenu();
        closeMonthPicker();
        closeSettingsPanel();
    }
});

// ── Swipe gestures ────────────────────────────────────────────────────────────
calendarEl.addEventListener('touchstart', function (e) {
    swipeStartX = e.touches[0].clientX;
}, { passive: true });

calendarEl.addEventListener('touchend', function (e) {
    if (swipeStartX === null) return;
    const dx = e.changedTouches[0].clientX - swipeStartX;
    swipeStartX = null;
    if (Math.abs(dx) > 60) changeMonth(dx < 0 ? 1 : -1);
}, { passive: true });

// ── Bulk select ───────────────────────────────────────────────────────────────
function toggleBulkSelect() {
    bulkSelectActive = !bulkSelectActive;
    if (!bulkSelectActive) clearBulkSelect();
    const btn = document.getElementById('bulkSelectBtn');
    if (btn) btn.textContent = bulkSelectActive ? '\u2715 Cancel Select' : '\u2611 Select';
    renderCalendar(currentDate);
}

function clearBulkSelect() {
    bulkSelectActive = false;
    selectedEventIds = new Set();
    updateBulkBar();
    const btn = document.getElementById('bulkSelectBtn');
    if (btn) btn.textContent = '\u2611 Select';
    renderCalendar(currentDate);
}

function toggleEventSelection(id, el) {
    const key = String(id);
    if (selectedEventIds.has(key)) {
        selectedEventIds.delete(key);
        if (el) el.classList.remove('selected-event');
    } else {
        selectedEventIds.add(key);
        if (el) el.classList.add('selected-event');
    }
    updateBulkBar();
}

function updateBulkBar() {
    const bar = document.getElementById('bulkActionsBar');
    const cnt = document.getElementById('bulkCount');
    if (!bar) return;
    if (selectedEventIds.size > 0) {
        bar.style.display = 'flex';
        if (cnt) cnt.textContent = selectedEventIds.size + ' selected';
    } else {
        bar.style.display = 'none';
    }
}

function applyBulkEdit() {
    if (selectedEventIds.size === 0) return;
    const color    = document.getElementById('bulkColor').value;
    const category = document.getElementById('bulkCategory').value;

    showSpinner();
    const body = new URLSearchParams();
    body.set('action',   'bulk_edit');
    body.set('ids',      Array.from(selectedEventIds).join(','));
    body.set('color',    color);
    body.set('category', category);

    fetch('ajax.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            hideSpinner();
            if (data.ok) {
                const ids = Array.from(selectedEventIds);
                events.forEach(function (ev) {
                    if (ids.indexOf(String(ev.id)) !== -1) {
                        if (color)    ev.color    = color;
                        if (category) ev.category = category;
                    }
                });
                clearBulkSelect();
            }
        })
        .catch(function () { hideSpinner(); });
}

function applyBulkDelete() {
    if (selectedEventIds.size === 0) return;
    showConfirmModal('Delete ' + selectedEventIds.size + ' selected event(s)?', function () {
        showSpinner();
        const body = new URLSearchParams();
        body.set('action', 'bulk_delete');
        body.set('ids',    Array.from(selectedEventIds).join(','));

        fetch('ajax.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                hideSpinner();
                if (data.ok) {
                    const ids = Array.from(selectedEventIds);
                    for (let i = events.length - 1; i >= 0; i--) {
                        if (ids.indexOf(String(events[i].id)) !== -1) {
                            events.splice(i, 1);
                        }
                    }
                    clearBulkSelect();
                }
            })
            .catch(function () { hideSpinner(); });
    });
}

// ── Browser notifications ─────────────────────────────────────────────────────
function toggleNotifications() {
    if (typeof Notification === 'undefined') {
        alert('Notifications not supported in this browser.');
        return;
    }
    if (Notification.permission === 'granted') {
        alert('Notifications are already enabled.');
    } else if (Notification.permission === 'denied') {
        alert('Notifications are blocked. Please allow them in browser settings.');
    } else {
        Notification.requestPermission().then(function (perm) {
            if (perm === 'granted') alert('Notifications enabled!');
        });
    }
}


// ── Month picker ──────────────────────────────────────────────────────────────
function toggleMonthPicker() {
    const picker = document.getElementById('monthPicker');
    if (!picker) return;
    if (picker.style.display === 'none' || picker.style.display === '') {
        renderMonthPicker();
        picker.style.display = 'block';
    } else {
        picker.style.display = 'none';
    }
}

function closeMonthPicker() {
    const picker = document.getElementById('monthPicker');
    if (picker) picker.style.display = 'none';
}

function renderMonthPicker() {
    const picker = document.getElementById('monthPicker');
    if (!picker) return;
    picker.innerHTML = '';

    const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    const nav = document.createElement('div');
    nav.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;';

    const prevBtn = document.createElement('button');
    prevBtn.textContent = '\u2039';
    prevBtn.style.cssText = 'background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--primary-dark);';
    prevBtn.onclick = function () { pickerYear--; renderMonthPicker(); };

    const yearEl = document.createElement('span');
    yearEl.textContent = pickerYear;
    yearEl.style.fontWeight = 'bold';

    const nextBtn = document.createElement('button');
    nextBtn.textContent = '\u203a';
    nextBtn.style.cssText = 'background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--primary-dark);';
    nextBtn.onclick = function () { pickerYear++; renderMonthPicker(); };

    nav.appendChild(prevBtn);
    nav.appendChild(yearEl);
    nav.appendChild(nextBtn);
    picker.appendChild(nav);

    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(4,1fr);gap:4px;';

    monthNames.forEach(function (name, idx) {
        const btn = document.createElement('button');
        btn.textContent = name;
        btn.style.cssText = 'padding:6px 4px;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-size:0.82rem;background:white;color:#333;transition:background 0.15s;';
        btn.onmouseover = function () { btn.style.background = 'var(--primary-light)'; };
        btn.onmouseout  = function () { btn.style.background = 'white'; };
        btn.onclick = function () { jumpToMonth(pickerYear, idx); };
        grid.appendChild(btn);
    });

    picker.appendChild(grid);
}

function jumpToMonth(year, month) {
    currentDate = new Date(year, month, 1);
    closeMonthPicker();
    renderCalendar(currentDate);
}

// ── Settings panel ────────────────────────────────────────────────────────────
function toggleSettingsPanel() {
    const panel = document.getElementById('settingsPanel');
    if (!panel) return;
    panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
}

function closeSettingsPanel() {
    const panel = document.getElementById('settingsPanel');
    if (panel) panel.style.display = 'none';
}

// ── Color themes ──────────────────────────────────────────────────────────────
const themes = {
    blue:   { primary: '#6B82F6', light: '#dbbefe', dark: '#1e3a8a' },
    purple: { primary: '#9333ea', light: '#e9d5ff', dark: '#581c87' },
    green:  { primary: '#16a34a', light: '#bbf7d0', dark: '#14532d' },
    orange: { primary: '#ea580c', light: '#fed7aa', dark: '#7c2d12' },
};

function applyTheme(name) {
    const t = themes[name];
    if (!t) return;
    let styleEl = document.getElementById('themeStyle');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'themeStyle';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = ':root { --primary: ' + t.primary + '; --primary-light: ' + t.light + '; --primary-dark: ' + t.dark + '; }';
    localStorage.setItem('colorTheme', name);
    const sel = document.getElementById('themeSelect');
    if (sel) sel.value = name;
}

// ── Event templates ───────────────────────────────────────────────────────────
function getTemplates() {
    try { return JSON.parse(localStorage.getItem('eventTemplates') || '[]'); }
    catch (e) { return []; }
}

function loadTemplateOptions() {
    const sel = document.getElementById('templateSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">-- Apply template --</option>';
    getTemplates().forEach(function (t, idx) {
        const opt = document.createElement('option');
        opt.value = idx;
        opt.textContent = t.name;
        sel.appendChild(opt);
    });
}

function applyTemplate(idx) {
    if (idx === '') return;
    const templates = getTemplates();
    const t = templates[parseInt(idx, 10)];
    if (!t) return;
    if (t.course_name)    document.getElementById('courseName').value    = t.course_name;
    if (t.instructor_name) document.getElementById('instructorName').value = t.instructor_name;
    if (t.start_time)     document.getElementById('startTime').value     = t.start_time;
    if (t.end_time)       document.getElementById('endTime').value       = t.end_time;
    if (t.color)          document.getElementById('eventColor').value    = t.color;
    if (t.category)       document.getElementById('eventCategory').value = t.category;
    if (t.priority)       document.getElementById('eventPriority').value = String(t.priority);
}

function saveAsTemplate() {
    const name = prompt('Template name:');
    if (!name) return;
    const tpl = {
        name:             name,
        course_name:      document.getElementById('courseName').value,
        instructor_name:  document.getElementById('instructorName').value,
        start_time:       document.getElementById('startTime').value,
        end_time:         document.getElementById('endTime').value,
        color:            document.getElementById('eventColor').value,
        category:         document.getElementById('eventCategory').value,
        priority:         parseInt(document.getElementById('eventPriority').value, 10),
    };
    const templates = getTemplates();
    templates.push(tpl);
    localStorage.setItem('eventTemplates', JSON.stringify(templates));
    loadTemplateOptions();
}

// ── Context menu ──────────────────────────────────────────────────────────────
function showContextMenu(event, x, y) {
    contextEventData = event;
    const menu = document.getElementById('contextMenu');
    if (!menu) return;
    menu.style.left    = x + 'px';
    menu.style.top     = y + 'px';
    menu.style.display = 'block';
}

function hideContextMenu() {
    const menu = document.getElementById('contextMenu');
    if (menu) menu.style.display = 'none';
    contextEventData = null;
}

function contextMenuEdit() {
    hideContextMenu();
    if (contextEventData) openModalForEdit([contextEventData]);
}

function contextMenuDuplicate() {
    hideContextMenu();
    if (!contextEventData) return;
    showSpinner();
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    const a1 = document.createElement('input'); a1.name = 'action';   a1.value = 'duplicate'; form.appendChild(a1);
    const a2 = document.createElement('input'); a2.name = 'event_id'; a2.value = contextEventData.id; form.appendChild(a2);
    document.body.appendChild(form);
    form.submit();
}

function contextMenuDelete() {
    hideContextMenu();
    if (!contextEventData) return;
    document.getElementById('deleteEventID').value = contextEventData.id;
    confirmDeleteEvent();
}

document.addEventListener('click', function (e) {
    const menu = document.getElementById('contextMenu');
    if (menu && !menu.contains(e.target)) hideContextMenu();

    const picker = document.getElementById('monthPicker');
    const h2     = document.getElementById('monthYear');
    if (picker && picker.style.display !== 'none' && !picker.contains(e.target) && e.target !== h2) {
        closeMonthPicker();
    }

    const panel = document.getElementById('settingsPanel');
    if (panel && panel.style.display === 'block' && !panel.contains(e.target) && !e.target.closest('[onclick="toggleSettingsPanel()"]')) {
        closeSettingsPanel();
    }
});

// ── Dark mode init IIFE ───────────────────────────────────────────────────────
(function () {
    const saved       = localStorage.getItem('darkMode');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (saved === '1' || (saved === null && prefersDark)) {
        document.body.classList.add('dark');
        const btn = document.getElementById('darkToggle');
        if (btn) btn.textContent = 'Light Mode';
    }
})();

// ── Initialize ────────────────────────────────────────────────────────────────
(function init() {
    // Load theme
    const savedTheme = localStorage.getItem('colorTheme');
    if (savedTheme && themes[savedTheme]) applyTheme(savedTheme);

    // Load settings into UI
    const firstDay  = getSetting('firstDay', '0');
    const clockFmt  = getSetting('clockFmt', '24');
    const dateFmt   = getSetting('dateFmt', 'MDY');

    document.querySelectorAll('input[name="firstDay"]').forEach(function (r) {
        r.checked = (r.value === firstDay);
    });
    document.querySelectorAll('input[name="clockFmt"]').forEach(function (r) {
        r.checked = (r.value === clockFmt);
    });
    document.querySelectorAll('[onchange*="dateFmt"]').forEach(function (s) {
        s.value = dateFmt;
    });

    // Restore last view
    const lastView = localStorage.getItem('lastView');
    if (lastView && document.getElementById('view-' + lastView)) {
        switchView(lastView);
    } else {
        renderCalendar(currentDate);
    }

    // Mark initial data range as fetched (monthly range)
    const y = currentDate.getFullYear(), m = currentDate.getMonth();
    fetchedRanges.push({
        start: y + '-' + padZ(m + 1) + '-01',
        end:   new Date(y, m + 1, 0).getFullYear() + '-' + padZ(m + 1) + '-' + padZ(new Date(y, m + 1, 0).getDate()),
    });

    updateClock();
    setInterval(updateClock, 1000);
    setInterval(checkNotifications, 60000);

    // Request notifications permission
    if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    // Weekly digest on load (#95)
    renderWeeklyDigest();
    // Load recent searches (#44)
    try { recentSearches = JSON.parse(localStorage.getItem('recentSearches') || '[]'); } catch(e) {}
    // Restore snap min setting
    const snapSel = document.getElementById('snapMinSelect');
    if (snapSel) snapSel.value = getSetting('snapMin', '15');
    // Restore quiet hours
    const qs = document.getElementById('quietHoursStart');
    const qe = document.getElementById('quietHoursEnd');
    if (qs) qs.value = getSetting('quietStart', '22:00');
    if (qe) qe.value = getSetting('quietEnd', '08:00');
    // Color hours toggle
    const cht = document.getElementById('colorHoursToggle');
    if (cht) cht.checked = getSetting('colorHours', '0') === '1';
    // Render mini sidebar calendar (#15)
    renderMiniCal();
    // Load notification history
    try { notifHistory = JSON.parse(localStorage.getItem('notifHistory') || '[]'); } catch(e) {}
    updateNotifCount();
    // Check overdue re-notification (#97)
    setInterval(checkOverdueRenotify, 30 * 60 * 1000);
    // Check holiday notification (#98)
    checkHolidayNotification();
})();

// ── Jump to date (#3) ──────────────────────────────────────────────────────────
function jumpToDate(dateStr) {
    if (!dateStr) return;
    currentDate = new Date(dateStr + 'T00:00:00');
    renderCalendar(currentDate);
}

// ── Sidebar toggle (#17) ───────────────────────────────────────────────────────
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const calArea = document.getElementById('calendarArea');
    if (!sidebar) return;
    const isHidden = sidebar.style.display === 'none' || sidebar.style.display === '';
    sidebar.style.display = isHidden ? 'block' : 'none';
    localStorage.setItem('sidebarOpen', isHidden ? '1' : '0');
    if (isHidden) {
        renderMiniCal();
        renderWeeklyDigest();
    }
}

// ── Mini calendar (#15) ────────────────────────────────────────────────────────
function renderMiniCal() {
    const el = document.getElementById('miniCal');
    if (!el) return;
    const d = new Date(currentDate);
    const year = d.getFullYear(), month = d.getMonth();
    const today = new Date();
    const totalDays = new Date(year, month + 1, 0).getDate();
    const firstDay = parseInt(getSetting('firstDay', '0'), 10);

    el.innerHTML = '';
    el.className = 'mini-cal';

    const header = document.createElement('div');
    header.className = 'mini-cal-header';

    const prev = document.createElement('button');
    prev.textContent = '‹'; prev.style.cssText = 'background:none;border:none;cursor:pointer;font-size:1rem;color:var(--primary);';
    prev.onclick = function() { d.setMonth(d.getMonth()-1); renderMiniCalAt(d.getFullYear(), d.getMonth()); };

    const title = document.createElement('span');
    title.textContent = d.toLocaleDateString('en-US', {month:'short', year:'numeric'});

    const next = document.createElement('button');
    next.textContent = '›'; next.style.cssText = 'background:none;border:none;cursor:pointer;font-size:1rem;color:var(--primary);';
    next.onclick = function() { d.setMonth(d.getMonth()+1); renderMiniCalAt(d.getFullYear(), d.getMonth()); };

    header.appendChild(prev); header.appendChild(title); header.appendChild(next);
    el.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'mini-cal-grid';

    const dayNames = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    for (let i = 0; i < 7; i++) {
        const dn = document.createElement('div');
        dn.className = 'mini-cal-day-name';
        dn.textContent = dayNames[(firstDay + i) % 7];
        grid.appendChild(dn);
    }

    const rawFirst = new Date(year, month, 1).getDay();
    const offset = (rawFirst - firstDay + 7) % 7;
    for (let i = 0; i < offset; i++) grid.appendChild(document.createElement('div'));

    const eventDates = new Set(events.map(function(e) { return e.date; }));
    for (let day = 1; day <= totalDays; day++) {
        const ds = year + '-' + padZ(month+1) + '-' + padZ(day);
        const cell = document.createElement('div');
        cell.className = 'mini-cal-day';
        if (eventDates.has(ds)) cell.classList.add('mini-cal-has-events');
        if (ds === toDateStr(today)) cell.classList.add('mini-cal-today');
        cell.textContent = day;
        cell.title = ds;
        cell.onclick = (function(dateStr) { return function() {
            currentDate = new Date(dateStr + 'T00:00:00');
            renderCalendar(currentDate);
        }; })(ds);
        grid.appendChild(cell);
    }
    el.appendChild(grid);
}

function renderMiniCalAt(year, month) {
    const el = document.getElementById('miniCal');
    if (!el) return;
    // Update display without changing currentDate
    const tempDate = new Date(year, month, 1);
    const today = new Date();
    const totalDays = new Date(year, month + 1, 0).getDate();
    const firstDay = parseInt(getSetting('firstDay', '0'), 10);

    el.innerHTML = '';
    el.className = 'mini-cal';

    const header = document.createElement('div');
    header.className = 'mini-cal-header';
    const prev = document.createElement('button');
    prev.textContent = '‹'; prev.style.cssText = 'background:none;border:none;cursor:pointer;font-size:1rem;color:var(--primary);';
    prev.onclick = function() { renderMiniCalAt(month === 0 ? year-1 : year, month === 0 ? 11 : month-1); };
    const title = document.createElement('span');
    title.textContent = tempDate.toLocaleDateString('en-US', {month:'short', year:'numeric'});
    const next = document.createElement('button');
    next.textContent = '›'; next.style.cssText = 'background:none;border:none;cursor:pointer;font-size:1rem;color:var(--primary);';
    next.onclick = function() { renderMiniCalAt(month === 11 ? year+1 : year, month === 11 ? 0 : month+1); };
    header.appendChild(prev); header.appendChild(title); header.appendChild(next);
    el.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'mini-cal-grid';
    const dayNames = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    for (let i = 0; i < 7; i++) {
        const dn = document.createElement('div'); dn.className = 'mini-cal-day-name';
        dn.textContent = dayNames[(firstDay + i) % 7]; grid.appendChild(dn);
    }
    const rawFirst = new Date(year, month, 1).getDay();
    const offset = (rawFirst - firstDay + 7) % 7;
    for (let i = 0; i < offset; i++) grid.appendChild(document.createElement('div'));
    const eventDates = new Set(events.map(function(e) { return e.date; }));
    for (let day = 1; day <= totalDays; day++) {
        const ds = year + '-' + padZ(month+1) + '-' + padZ(day);
        const cell = document.createElement('div');
        cell.className = 'mini-cal-day';
        if (eventDates.has(ds)) cell.classList.add('mini-cal-has-events');
        if (ds === toDateStr(today)) cell.classList.add('mini-cal-today');
        cell.textContent = day;
        cell.onclick = (function(dateStr) { return function() {
            currentDate = new Date(dateStr + 'T00:00:00');
            closeMonthPicker();
            renderCalendar(currentDate);
        }; })(ds);
        grid.appendChild(cell);
    }
    el.appendChild(grid);
}

// ── Calendar visibility toggle (#52) ──────────────────────────────────────────
function toggleCalendarVisibility(calId, visible) {
    if (visible) hiddenCalendars.delete(String(calId));
    else hiddenCalendars.add(String(calId));
    renderCalendar(currentDate);
}

// ── Year view (#9) ────────────────────────────────────────────────────────────
function renderYear(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = '';
    const year = date.getFullYear();
    monthYearEl.textContent = 'Year ' + year;
    const today = new Date();

    const container = document.createElement('div');
    container.className = 'year-view';

    const eventDates = {};
    events.forEach(function(e) {
        if (!eventDates[e.date]) eventDates[e.date] = 0;
        eventDates[e.date]++;
    });

    for (let m = 0; m < 12; m++) {
        const cell = document.createElement('div');
        cell.className = 'year-month-cell';

        const mName = document.createElement('div');
        mName.className = 'year-month-name';
        mName.textContent = new Date(year, m, 1).toLocaleDateString('en-US', {month:'long'});
        cell.appendChild(mName);

        const miniGrid = document.createElement('div');
        miniGrid.className = 'year-month-mini';
        const totalDays = new Date(year, m+1, 0).getDate();
        const firstDay = parseInt(getSetting('firstDay','0'), 10);
        const rawFirst = new Date(year, m, 1).getDay();
        const offset = (rawFirst - firstDay + 7) % 7;
        for (let i = 0; i < offset; i++) {
            const empty = document.createElement('div'); miniGrid.appendChild(empty);
        }
        for (let day = 1; day <= totalDays; day++) {
            const ds = year + '-' + padZ(m+1) + '-' + padZ(day);
            const dc = document.createElement('div');
            dc.className = 'year-day-cell';
            dc.textContent = day;
            if (eventDates[ds]) dc.classList.add('year-day-has-events');
            if (ds === toDateStr(today)) dc.classList.add('year-day-today');
            // Holiday indicator
            if (typeof publicHolidays !== 'undefined' && publicHolidays[ds]) {
                dc.title = publicHolidays[ds];
                dc.style.background = '#fee2e2';
                dc.style.color = '#b91c1c';
            }
            miniGrid.appendChild(dc);
        }
        cell.appendChild(miniGrid);

        const count = Object.keys(eventDates).filter(function(d) {
            return d.startsWith(year + '-' + padZ(m+1));
        }).reduce(function(acc, k) { return acc + eventDates[k]; }, 0);
        if (count > 0) {
            const badge = document.createElement('div');
            badge.style.cssText = 'font-size:9px;color:var(--primary);text-align:center;margin-top:4px;font-weight:bold;';
            badge.textContent = count + ' events';
            cell.appendChild(badge);
        }

        cell.onclick = (function(yr, mn) { return function() {
            currentDate = new Date(yr, mn, 1);
            switchView('monthly');
        }; })(year, m);

        container.appendChild(cell);
    }
    calendarEl.appendChild(container);
}

// ── Quarter view (#10) ────────────────────────────────────────────────────────
function renderQuarter(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = '';
    const year = date.getFullYear();
    const quarter = Math.floor(date.getMonth() / 3);
    const startMonth = quarter * 3;
    monthYearEl.textContent = 'Q' + (quarter+1) + ' ' + year;

    const container = document.createElement('div');
    container.className = 'quarter-view';

    for (let mi = 0; mi < 3; mi++) {
        const m = startMonth + mi;
        const monthDate = new Date(year, m, 1);

        const col = document.createElement('div');
        col.style.cssText = 'border:1px solid #e5e7eb;border-radius:8px;padding:10px;';

        const mTitle = document.createElement('div');
        mTitle.style.cssText = 'font-weight:bold;font-size:0.9rem;color:var(--primary-dark);margin-bottom:8px;text-align:center;';
        mTitle.textContent = monthDate.toLocaleDateString('en-US', {month:'long', year:'numeric'});
        col.appendChild(mTitle);

        const tempDate = new Date(year, m, 1);
        const today = new Date();
        const totalDays = new Date(year, m+1, 0).getDate();
        const firstDay = parseInt(getSetting('firstDay','0'), 10);
        const grid = document.createElement('div');
        grid.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:2px;font-size:10px;text-align:center;';

        const dayNames = ['Su','Mo','Tu','We','Th','Fr','Sa'];
        for (let i = 0; i < 7; i++) {
            const dn = document.createElement('div');
            dn.style.cssText = 'font-weight:bold;color:#9ca3af;font-size:9px;';
            dn.textContent = dayNames[(firstDay+i)%7];
            grid.appendChild(dn);
        }
        const rawFirst = new Date(year, m, 1).getDay();
        const offset = (rawFirst - firstDay + 7) % 7;
        for (let i = 0; i < offset; i++) grid.appendChild(document.createElement('div'));

        for (let day = 1; day <= totalDays; day++) {
            const ds = year + '-' + padZ(m+1) + '-' + padZ(day);
            const dc = document.createElement('div');
            dc.style.cssText = 'padding:3px 2px;border-radius:3px;cursor:pointer;';
            dc.textContent = day;
            const evCount = events.filter(function(e) { return e.date === ds; }).length;
            if (evCount > 0) { dc.style.background = 'var(--primary)'; dc.style.color = 'white'; }
            if (ds === toDateStr(today)) { dc.style.background = '#ef4444'; dc.style.color = 'white'; dc.style.borderRadius = '50%'; }
            dc.onclick = (function(dateStr) { return function() {
                currentDate = new Date(dateStr + 'T00:00:00');
                switchView('daily');
            }; })(ds);
            grid.appendChild(dc);
        }
        col.appendChild(grid);
        container.appendChild(col);
    }
    calendarEl.appendChild(container);
}

// ── Timeline / Gantt view (#11) ───────────────────────────────────────────────
function renderTimeline(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = '';

    const startDate = new Date(date);
    startDate.setDate(1);
    const endDate = new Date(startDate);
    endDate.setMonth(endDate.getMonth() + 1);
    endDate.setDate(0);

    const totalDays = endDate.getDate();
    const startStr = toDateStr(startDate);
    const endStr = toDateStr(endDate);

    monthYearEl.textContent = 'Timeline: ' + startDate.toLocaleDateString('en-US', {month:'long', year:'numeric'});

    const container = document.createElement('div');
    container.className = 'timeline-view';

    const header = document.createElement('div');
    header.className = 'timeline-header';
    header.style.cssText = 'display:flex;margin-bottom:4px;';
    const labelCol = document.createElement('div');
    labelCol.style.cssText = 'width:160px;flex-shrink:0;font-weight:bold;font-size:11px;padding:4px;color:#6b7280;';
    labelCol.textContent = 'Event';
    header.appendChild(labelCol);
    const dayHeader = document.createElement('div');
    dayHeader.style.cssText = 'flex:1;display:flex;';
    for (let d = 1; d <= totalDays; d++) {
        const dc = document.createElement('div');
        dc.style.cssText = 'flex:1;text-align:center;font-size:9px;color:#9ca3af;border-left:1px solid #f3f4f6;padding:2px 0;';
        dc.textContent = d;
        const ds = startDate.getFullYear() + '-' + padZ(startDate.getMonth()+1) + '-' + padZ(d);
        if (ds === todayStr()) dc.style.color = 'var(--primary)';
        dayHeader.appendChild(dc);
    }
    header.appendChild(dayHeader);
    container.appendChild(header);

    const vis = visibleEvents().filter(function(e) {
        return e.date >= startStr && e.date <= endStr;
    });
    const uniqueIds = [];
    const seen = {};
    vis.forEach(function(e) { if (!seen[e.id]) { seen[e.id] = true; uniqueIds.push(e); } });

    if (uniqueIds.length === 0) {
        const empty = createEmptyState('📊', 'No events this month', 'Navigate to a period with events.', null);
        container.appendChild(empty);
    }

    uniqueIds.forEach(function(event) {
        const row = document.createElement('div');
        row.className = 'timeline-event-row';

        const label = document.createElement('div');
        label.className = 'timeline-event-label';
        label.textContent = event.title.split(' - ')[0];
        label.title = event.title;
        row.appendChild(label);

        const barArea = document.createElement('div');
        barArea.className = 'timeline-bar-area';

        const startD = new Date(event.start + 'T00:00:00');
        const endD   = new Date(event.end + 'T00:00:00');
        const s = Math.max(1, startD.getDate());
        const e2 = Math.min(totalDays, endD.getDate());
        const leftPct  = (s - 1) / totalDays * 100;
        const widthPct = Math.max(1/totalDays*100, (e2 - s + 1) / totalDays * 100);

        const bar = document.createElement('div');
        bar.className = 'timeline-bar';
        bar.style.left  = leftPct + '%';
        bar.style.width = widthPct + '%';
        bar.style.backgroundColor = event.color || '#6B82F6';
        bar.textContent = event.title.split(' - ')[0];
        bar.onclick = function() { openModalForEdit([event]); };
        barArea.appendChild(bar);
        row.appendChild(barArea);
        container.appendChild(row);
    });

    calendarEl.appendChild(container);
}

// ── Heatmap view (#12) ────────────────────────────────────────────────────────
function renderHeatmap(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = '';
    const year = date.getFullYear();
    monthYearEl.textContent = 'Heatmap ' + year;

    const container = document.createElement('div');
    container.className = 'heatmap-view';

    const title = document.createElement('div');
    title.style.cssText = 'font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;';
    title.textContent = 'Event density per day (darker = more events)';
    container.appendChild(title);

    const densityMap = {};
    let maxCount = 0;
    events.forEach(function(e) {
        if (!densityMap[e.date]) densityMap[e.date] = 0;
        densityMap[e.date]++;
        if (densityMap[e.date] > maxCount) maxCount = densityMap[e.date];
    });

    const grid = document.createElement('div');
    grid.className = 'heatmap-grid';

    const totalDays = 366;
    const startDate = new Date(year, 0, 1);

    for (let i = 0; i < totalDays; i++) {
        const d = new Date(startDate);
        d.setDate(startDate.getDate() + i);
        if (d.getFullYear() !== year) break;
        const ds = toDateStr(d);
        const count = densityMap[ds] || 0;
        const intensity = maxCount > 0 ? count / maxCount : 0;
        const r = Math.round(107 + (0 - 107) * intensity);
        const g = Math.round(130 + (58 - 130) * intensity);
        const b = Math.round(246 + (246 - 246) * intensity);
        const alpha = intensity > 0 ? 0.2 + 0.8 * intensity : 0.08;

        const cell = document.createElement('div');
        cell.className = 'heatmap-cell';
        cell.style.background = count > 0 ? `rgba(${r},${g},${b},${alpha})` : '#e5e7eb22';
        cell.title = ds + ' — ' + count + ' event' + (count !== 1 ? 's' : '');
        if (ds === todayStr()) { cell.style.outline = '2px solid var(--primary)'; }
        cell.onclick = (function(dateStr) { return function() {
            currentDate = new Date(dateStr + 'T00:00:00');
            switchView('daily');
        }; })(ds);
        grid.appendChild(cell);
    }
    container.appendChild(grid);

    // Legend
    const legend = document.createElement('div');
    legend.style.cssText = 'display:flex;align-items:center;gap:4px;margin-top:8px;font-size:10px;color:#9ca3af;';
    legend.innerHTML = 'Less ';
    for (let li = 0; li <= 4; li++) {
        const lc = document.createElement('div');
        lc.style.cssText = 'width:12px;height:12px;border-radius:2px;';
        lc.style.background = `rgba(107,130,246,${0.1 + 0.2*li})`;
        legend.appendChild(lc);
    }
    legend.innerHTML += ' More';
    container.appendChild(legend);
    calendarEl.appendChild(container);
}

// ── Empty state helper (#20) ──────────────────────────────────────────────────
function createEmptyState(icon, title, sub, clearFn) {
    const el = document.createElement('div');
    el.className = 'empty-state';
    el.innerHTML = '<span class="empty-state-icon">' + icon + '</span>'
        + '<div class="empty-state-title">' + title + '</div>'
        + '<div class="empty-state-sub">' + sub + '</div>';
    if (clearFn) {
        const btn = document.createElement('button');
        btn.className = 'empty-state-clear-btn';
        btn.textContent = 'Clear Filters';
        btn.onclick = clearFn;
        el.appendChild(btn);
    }
    return el;
}

// ── changeMonth for all views ──────────────────────────────────────────────────
function changeMonth(offset) {
    if (currentView === 'year')      { currentDate.setFullYear(currentDate.getFullYear() + offset); }
    else if (currentView === 'quarter')  { currentDate.setMonth(currentDate.getMonth() + offset * 3); }
    else if (currentView === 'timeline') { currentDate.setMonth(currentDate.getMonth() + offset); }
    else if (currentView === 'heatmap')  { currentDate.setFullYear(currentDate.getFullYear() + offset); }
    else if (currentView === 'daily')    { currentDate.setDate(currentDate.getDate() + offset); }
    else if (currentView === 'weekly' || currentView === 'biweekly') {
        currentDate.setDate(currentDate.getDate() + offset * 7 * (currentView === 'biweekly' ? 2 : 1));
    }
    else { currentDate.setMonth(currentDate.getMonth() + offset); }
    renderCalendar(currentDate);
}

// ── Focus mode (#86) ──────────────────────────────────────────────────────────
function toggleFocusMode() {
    focusModeActive = !focusModeActive;
    document.body.classList.toggle('focus-mode', focusModeActive);
    const btn = document.getElementById('focusModeBtn');
    if (btn) btn.textContent = focusModeActive ? '✦ Exit Focus' : '🌟 Focus Mode';
    renderCalendar(currentDate);
}

// ── Filter panel (#41) ────────────────────────────────────────────────────────
function applyFilterPanel() {
    activeFilters = {
        category:  (document.getElementById('filterCategory')  || {}).value || '',
        priority:  (document.getElementById('filterPriority')  || {}).value || '',
        color:     (document.getElementById('filterColor')     || {}).value || '',
        tag:       (document.getElementById('filterTag')       || {}).value || '',
        attendee:  (document.getElementById('filterAttendee')  || {}).value || '',
        location:  (document.getElementById('filterLocation')  || {}).value || '',
        dateFrom:  (document.getElementById('filterDateFrom')  || {}).value || '',
        dateTo:    (document.getElementById('filterDateTo')    || {}).value || '',
        logic:     (document.getElementById('filterLogic')     || {}).value || 'AND',
    };
    renderCalendar(currentDate);
}

function clearFilters() {
    activeFilters = {};
    ['filterCategory','filterTag','filterAttendee','filterLocation','filterDateFrom','filterDateTo'].forEach(function(id) {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    const fp = document.getElementById('filterPriority'); if (fp) fp.value = '';
    const fc = document.getElementById('filterColor'); if (fc) fc.value = '#000000';
    renderCalendar(currentDate);
}

function clearFilterColor() {
    const fc = document.getElementById('filterColor');
    if (fc) { fc.value = '#000000'; applyFilterPanel(); }
}


function loadFilterPreset(filtersJson) {
    if (!filtersJson) return;
    try {
        const f = JSON.parse(filtersJson);
        activeFilters = f;
        if (f.category && document.getElementById('filterCategory')) document.getElementById('filterCategory').value = f.category;
        if (f.tag && document.getElementById('filterTag')) document.getElementById('filterTag').value = f.tag;
        if (f.priority && document.getElementById('filterPriority')) document.getElementById('filterPriority').value = f.priority;
        renderCalendar(currentDate);
    } catch(e) {}
}

// ── filterAndRender with recent searches (#44) ────────────────────────────────
function filterAndRender(q) {
    filterQuery = q || '';
    if (q && q.length > 1) {
        recentSearches = recentSearches.filter(function(s) { return s !== q; });
        recentSearches.unshift(q);
        if (recentSearches.length > 10) recentSearches.pop();
        try { localStorage.setItem('recentSearches', JSON.stringify(recentSearches)); } catch(e) {}
    }
    showRecentSearches(q);
    // Remove any old no-results state
    var nr = document.querySelector('.no-results-state');
    if (nr) nr.remove();
    renderCalendar(currentDate);
    // Show no-results empty state if nothing visible (#47)
    if (q) {
        setTimeout(function() {
            var cal = document.getElementById('calendar');
            if (!cal) return;
            var hasContent = cal.querySelector('.event, .timed-event, .agenda-event-row, .list-event-row');
            if (!hasContent && !cal.querySelector('.no-results-state')) {
                var noRes = document.createElement('div');
                noRes.className = 'no-results-state';
                noRes.innerHTML = '<div class="no-results-icon">🔍</div>'
                    + '<div>No events match <strong>"' + q.replace(/</g, '&lt;') + '"</strong></div>'
                    + '<button class="no-results-clear" onclick="clearSearch()">Clear Search</button>';
                cal.appendChild(noRes);
            }
        }, 250);
    }
}

function showRecentSearches(q) {
    const dd = document.getElementById('recentSearchesDropdown');
    const sb = document.getElementById('searchBar');
    if (!dd || !sb) return;
    const matches = recentSearches.filter(function(s) { return s !== q; }).slice(0, 5);
    if (matches.length === 0) { dd.style.display = 'none'; return; }
    dd.innerHTML = '';
    matches.forEach(function(s) {
        const item = document.createElement('div');
        item.className = 'recent-search-item';
        item.innerHTML = '🕐 ' + s;
        item.onclick = function() { sb.value = s; filterAndRender(s); hideRecentSearches(); };
        dd.appendChild(item);
    });
    const rect = sb.getBoundingClientRect();
    dd.style.cssText = 'display:block;position:fixed;left:' + rect.left + 'px;top:' + (rect.bottom+2) + 'px;width:' + rect.width + 'px;background:white;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:9000;';
}

function hideRecentSearches() {
    const dd = document.getElementById('recentSearchesDropdown');
    if (dd) dd.style.display = 'none';
}

// Keyboard shortcut / to focus search (#46)
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === '/') { e.preventDefault(); const sb = document.getElementById('searchBar'); if (sb) { sb.focus(); sb.select(); } }
    if (e.key === '?') { e.preventDefault(); showShortcutsModal(); }
    if (e.key === 'n' || e.key === 'N') { e.preventDefault(); openModalForAdd(todayStr()); }
    if (e.key === '1') switchView('monthly');
    if (e.key === '2') switchView('weekly');
    if (e.key === '3') switchView('daily');
    if (e.key === '4') switchView('agenda');
    if (e.key === 'd' || e.key === 'D') toggleDark();
    if (e.key === 'f' || e.key === 'F') toggleFocusMode();
});

document.getElementById('searchBar') && document.getElementById('searchBar').addEventListener('blur', function() {
    setTimeout(hideRecentSearches, 200);
});

// ── Shortcuts modal (#4) ──────────────────────────────────────────────────────
function showShortcutsModal() {
    const m = document.getElementById('shortcutsModal');
    if (m) m.style.display = 'flex';
}
function closeShortcutsModal() {
    const m = document.getElementById('shortcutsModal');
    if (m) m.style.display = 'none';
}

// ── Build day events column with drag-to-create (#2), color hours (#18), holidays (#78) ──
function buildDayEventsCol(ds, isToday, hourRange) {
    const range = hourRange || { start: 0, end: 24 };
    const col = document.createElement('div');
    col.className = 'day-events-col' + (isToday ? ' today-col' : '');
    col.dataset.date = ds;
    col.style.cssText = 'position:relative;flex:1;min-width:0;border-right:1px solid #e5e7eb;';

    const snapMin = parseInt(getSetting('snapMin', '15'), 10);
    const colorHours = getSetting('colorHours', '0') === '1';

    // Hour slots (for click-to-add and visual grid)
    for (let h = range.start; h < range.end; h++) {
        const slot = document.createElement('div');
        slot.className = 'hour-slot';
        slot.dataset.hour = h;
        slot.style.cssText = 'height:60px;border-bottom:1px solid #f3f4f6;box-sizing:border-box;position:relative;cursor:pointer;';
        // Color hour blocks (#18)
        if (colorHours) {
            if (h >= 6 && h < 12) slot.classList.add('morning-slot');
            else if (h >= 12 && h < 17) slot.classList.add('afternoon-slot');
            else if (h >= 17 && h < 22) slot.classList.add('evening-slot');
        }
        slot.onclick = (function(dateStr, hour) {
            return function(e) {
                if (e.target !== slot) return;
                openModalForAdd(dateStr, padZ(hour) + ':00', padZ(hour + 1) + ':00');
            };
        })(ds, h);
        col.appendChild(slot);
    }

    // Add public holidays badge (#78)
    if (typeof publicHolidays !== 'undefined' && publicHolidays[ds]) {
        const hBadge = document.createElement('div');
        hBadge.className = 'holiday-badge';
        hBadge.textContent = '🎉 ' + publicHolidays[ds];
        hBadge.style.cssText = 'position:absolute;top:0;left:0;right:0;font-size:9px;background:#fee2e2;color:#b91c1c;padding:1px 3px;z-index:4;';
        col.appendChild(hBadge);
    }

    // Render events
    const dayEvents = visibleEvents().filter(function(e) { return e.date === ds && e.start_time; });
    const layout = layoutOverlappingEvents(dayEvents);
    layout.forEach(function(item) {
        const el = createTimedEvent(item.event, range);
        if (!el) return;
        const totalCols = item.totalCols || 1;
        const col_idx = item.col || 0;
        el.style.left = (col_idx * 100 / totalCols) + '%';
        el.style.width = (100 / totalCols) + '%';
        col.appendChild(el);
    });

    // Drag-to-create (#2)
    let createStartMins = null;
    let createIndicator = null;
    col.addEventListener('mousedown', function(e) {
        if (e.target.classList.contains('timed-event') || e.target.closest('.timed-event')) return;
        if (e.button !== 0) return;
        const colRect = col.getBoundingClientRect();
        const relY = e.clientY - colRect.top;
        createStartMins = Math.round((relY + range.start * 60) / snapMin) * snapMin;
        dragCreateState = { col: col, ds: ds, startMins: createStartMins, range: range };
        createIndicator = document.createElement('div');
        createIndicator.className = 'drag-create-indicator';
        createIndicator.style.top  = (createStartMins - range.start * 60) + 'px';
        createIndicator.style.left = '0'; createIndicator.style.right = '0';
        createIndicator.style.height = snapMin + 'px';
        col.appendChild(createIndicator);
        e.preventDefault();
    });
    col.addEventListener('mousemove', function(e) {
        if (!dragCreateState || dragCreateState.col !== col) return;
        if (!createIndicator) return;
        const colRect = col.getBoundingClientRect();
        const relY = e.clientY - colRect.top;
        const curMins = Math.round((relY + range.start * 60) / snapMin) * snapMin;
        const topMins = Math.min(createStartMins || curMins, curMins);
        const btmMins = Math.max(createStartMins || curMins, curMins);
        createIndicator.style.top    = (topMins - range.start * 60) + 'px';
        createIndicator.style.height = Math.max(snapMin, btmMins - topMins) + 'px';
    });
    col.addEventListener('mouseup', function(e) {
        if (!dragCreateState || dragCreateState.col !== col) return;
        const colRect = col.getBoundingClientRect();
        const relY = e.clientY - colRect.top;
        const endMins = Math.round((relY + range.start * 60) / snapMin) * snapMin;
        const startM = Math.min(createStartMins || endMins, endMins);
        const endM   = Math.max((createStartMins || endMins) + snapMin, endMins);
        if (createIndicator) { createIndicator.remove(); createIndicator = null; }
        dragCreateState = null;
        createStartMins = null;
        const durMins = endM - startM;
        if (durMins > 0) {
            openModalForAdd(ds, minsToTimeStr(startM), minsToTimeStr(endM));
        }
    });

    return col;
}

// ── Time indicator with auto-scroll to current time (#5, #7) ─────────────────
function updateTimeIndicator(body, container) {
    if (!body || !container) return;
    const now = new Date();
    const range = getHourRange();
    const nm = now.getHours() * 60 + now.getMinutes();
    if (nm < range.start * 60 || nm > range.end * 60) return;
    let line = container.querySelector('.time-indicator');
    if (!line) {
        line = document.createElement('div');
        line.className = 'time-indicator';
        line.style.cssText = 'position:absolute;left:0;right:0;height:2px;background:#ef4444;z-index:10;pointer-events:none;';
        body.appendChild(line);
    }
    const topPx = nm - range.start * 60;
    line.style.top = topPx + 'px';
    // Smooth scroll to current time on initial open (#5)
    const scrollTo = Math.max(0, nm - range.start * 60 - 120);
    if (body._firstScroll !== false) {
        body._firstScroll = false;
        body.scrollTo({top: scrollTo, behavior: 'smooth'});
    }
}

// ── Expand/collapse all-day row (#8) ──────────────────────────────────────────
function toggleAllDayRow(btn) {
    const row = btn.closest('.allday-row');
    if (!row) return;
    row.classList.toggle('expanded');
    btn.textContent = row.classList.contains('expanded') ? '▲' : '▼';
}

// ── Markdown rendering in notes (#23) ─────────────────────────────────────────
let notesPreviewVisible = false;
function toggleNotesPreview() {
    notesPreviewVisible = !notesPreviewVisible;
    const preview = document.getElementById('notesPreview');
    const textarea = document.getElementById('eventNotes');
    if (!preview) return;
    if (notesPreviewVisible) {
        preview.style.display = 'block';
        if (typeof marked !== 'undefined' && textarea) {
            preview.innerHTML = marked.parse(textarea.value || '');
        }
    } else {
        preview.style.display = 'none';
    }
}
function updateNotesPreview() {
    if (!notesPreviewVisible) return;
    const preview = document.getElementById('notesPreview');
    const textarea = document.getElementById('eventNotes');
    if (preview && typeof marked !== 'undefined' && textarea) {
        preview.innerHTML = marked.parse(textarea.value || '');
    }
}

// ── Location auto-link (#25) ──────────────────────────────────────────────────
function updateLocationLink() {
    const input = document.getElementById('eventLocation');
    const linkDiv = document.getElementById('locationLink');
    if (!input || !linkDiv) return;
    const loc = input.value.trim();
    if (loc) {
        linkDiv.innerHTML = '📍 <a href="https://maps.google.com/?q=' + encodeURIComponent(loc) + '" target="_blank" rel="noopener" style="color:var(--primary);font-size:0.8rem;">Open in Google Maps</a>';
    } else {
        linkDiv.innerHTML = '';
    }
}

// ── Zoom URL auto-open (#73) ──────────────────────────────────────────────────
function updateZoomBtn() {
    const input = document.getElementById('eventZoomUrl');
    const div = document.getElementById('zoomJoinBtn');
    if (!input || !div) return;
    const url = input.value.trim();
    if (url) {
        div.innerHTML = '<a href="' + url + '" target="_blank" class="export-btn" style="font-size:0.8rem;display:inline-block;">🎥 Join Meeting</a>';
        div.style.display = 'block';
    } else {
        div.style.display = 'none';
    }
}

// ── Deadline countdown (#85) ──────────────────────────────────────────────────
function updateDeadlineCountdown() {
    const input = document.getElementById('eventDeadline');
    const div = document.getElementById('deadlineCountdown');
    if (!input || !div) return;
    const val = input.value;
    if (!val) { div.textContent = ''; return; }
    const diff = new Date(val) - new Date();
    if (diff < 0) { div.textContent = '⏰ Overdue!'; div.style.color = '#b91c1c'; return; }
    const days = Math.floor(diff / 86400000);
    const hrs  = Math.floor((diff % 86400000) / 3600000);
    div.textContent = '⏳ Deadline in ' + (days > 0 ? days + 'd ' : '') + hrs + 'h';
    div.style.color = diff < 86400000 ? '#b91c1c' : '#d97706';
}

// ── Actual vs Planned time display (#88) ──────────────────────────────────────
function updateActualVsPlanned() {
    const ps = document.getElementById('startTime'); const pe = document.getElementById('endTime');
    const as = document.getElementById('eventActualStart'); const ae = document.getElementById('eventActualEnd');
    const div = document.getElementById('actualVsPlanned');
    if (!div || !ps || !pe || !as || !ae) return;
    if (!as.value || !ae.value || !ps.value || !pe.value) { div.textContent = ''; return; }
    const planned = timeToMinutes(pe.value) - timeToMinutes(ps.value);
    const actual  = timeToMinutes(ae.value) - timeToMinutes(as.value);
    const diff    = actual - planned;
    div.textContent = '📊 Planned: ' + planned + 'min | Actual: ' + actual + 'min | Diff: ' + (diff >= 0 ? '+' : '') + diff + 'min';
    div.style.color = Math.abs(diff) > 30 ? '#b91c1c' : '#6b7280';
}

// ── Subtasks checklist (#22) ──────────────────────────────────────────────────
function renderSubtasksChecklist() {
    const textarea = document.getElementById('eventSubtasks');
    const div = document.getElementById('subtasksChecklist');
    if (!div || !textarea) return;
    const lines = (textarea.value || '').split('\n').filter(Boolean);
    if (lines.length === 0) { div.innerHTML = ''; return; }
    const total = lines.length;
    const done  = lines.filter(function(l) { return l.trim().startsWith('[x]') || l.trim().startsWith('[X]'); }).length;
    div.innerHTML = '<div style="font-size:0.8rem;color:#6b7280;margin-bottom:4px;">' + done + '/' + total + ' completed</div>';
    const prog = document.createElement('div');
    prog.className = 'time-budget-bar';
    const fill = document.createElement('div');
    fill.className = 'time-budget-fill';
    fill.style.width = (total > 0 ? (done/total*100) : 0) + '%';
    prog.appendChild(fill);
    div.appendChild(prog);
}

// ── Duplicate event dialog (#37) ──────────────────────────────────────────────
function duplicateEventDialog() {
    const id = document.getElementById('eventID').value;
    if (!id) return;
    duplicateEventId = id;
    closeModal();
    const m = document.getElementById('duplicateDialog');
    if (m) m.style.display = 'flex';
}
function confirmDuplicate() {
    const offset = parseInt(document.getElementById('dupOffsetDays').value || '1', 10);
    closeDuplicateDialog();
    if (!duplicateEventId) return;
    showSpinner();
    const form = document.createElement('form');
    form.method = 'POST'; form.style.display = 'none';
    const a1 = document.createElement('input'); a1.name = 'action'; a1.value = 'duplicate'; form.appendChild(a1);
    const a2 = document.createElement('input'); a2.name = 'event_id'; a2.value = duplicateEventId; form.appendChild(a2);
    const a3 = document.createElement('input'); a3.name = 'offset_days'; a3.value = offset; form.appendChild(a3);
    document.body.appendChild(form); form.submit();
}
function closeDuplicateDialog() {
    const m = document.getElementById('duplicateDialog'); if (m) m.style.display = 'none';
}

// ── Move event dialog (#38) ───────────────────────────────────────────────────
function contextMenuMoveEvent() {
    hideContextMenu();
    if (!contextEventData) return;
    moveEventId = contextEventData.id;
    const m = document.getElementById('moveEventDialog');
    if (!m) return;
    document.getElementById('moveNewStart').value = contextEventData.start || contextEventData.date;
    document.getElementById('moveNewEnd').value   = contextEventData.end   || contextEventData.date;
    m.style.display = 'flex';
}
function confirmMoveEvent() {
    const newStart = document.getElementById('moveNewStart').value;
    const newEnd   = document.getElementById('moveNewEnd').value;
    closeMoveDialog();
    if (!moveEventId || !newStart || !newEnd) return;
    showSpinner();
    const form = document.createElement('form');
    form.method = 'POST'; form.style.display = 'none';
    const a1 = document.createElement('input'); a1.name = 'action'; a1.value = 'move_event'; form.appendChild(a1);
    const a2 = document.createElement('input'); a2.name = 'event_id'; a2.value = moveEventId; form.appendChild(a2);
    const a3 = document.createElement('input'); a3.name = 'new_start_date'; a3.value = newStart; form.appendChild(a3);
    const a4 = document.createElement('input'); a4.name = 'new_end_date'; a4.value = newEnd; form.appendChild(a4);
    document.body.appendChild(form); form.submit();
}
function closeMoveDialog() {
    const m = document.getElementById('moveEventDialog'); if (m) m.style.display = 'none';
}

// ── Skip occurrence (#32) ─────────────────────────────────────────────────────
function contextMenuSkipOccurrence() {
    hideContextMenu();
    if (!contextEventData || contextEventData.recurrence === 'none') {
        showNotifToast('Only recurring events can have occurrences skipped.');
        return;
    }
    showSpinner();
    const form = document.createElement('form');
    form.method = 'POST'; form.style.display = 'none';
    const a1 = document.createElement('input'); a1.name = 'action'; a1.value = 'skip_occurrence'; form.appendChild(a1);
    const a2 = document.createElement('input'); a2.name = 'event_id'; a2.value = contextEventData.id; form.appendChild(a2);
    const a3 = document.createElement('input'); a3.name = 'skip_date'; a3.value = contextEventData.date; form.appendChild(a3);
    document.body.appendChild(form); form.submit();
}

// ── Open Zoom link (#73) ──────────────────────────────────────────────────────
function contextMenuOpenZoom() {
    hideContextMenu();
    if (!contextEventData) return;
    const url = contextEventData.zoom_url || contextEventData.event_url;
    if (url) window.open(url, '_blank');
    else showNotifToast('No meeting URL set for this event.');
}

// ── Quick add natural language (#36) ──────────────────────────────────────────
function showQuickAdd() {
    const m = document.getElementById('quickAddModal');
    if (m) { m.style.display = 'flex'; setTimeout(function(){ document.getElementById('quickAddInput').focus(); }, 50); }
}
function closeQuickAddModal() {
    const m = document.getElementById('quickAddModal'); if (m) m.style.display = 'none';
}

function parseQuickAdd() {
    const input = document.getElementById('quickAddInput');
    if (!input || !input.value.trim()) return;
    const text = input.value.trim();
    const result = parseNaturalLanguage(text);
    const preview = document.getElementById('quickAddPreview');
    if (preview) {
        preview.style.display = 'block';
        preview.textContent = '📅 ' + result.date + (result.start ? ' ' + result.start + '-' + result.end : '') + ' | ' + result.title;
    }
    closeQuickAddModal();
    openModalForAdd(result.date, result.start, result.end);
    setTimeout(function() {
        if (result.title && document.getElementById('courseName')) {
            document.getElementById('courseName').value = result.title;
        }
    }, 100);
}

function parseNaturalLanguage(text) {
    const today = new Date();
    let date = toDateStr(today);
    let startTime = '09:00', endTime = '10:00';
    let title = text;

    // Remove time from title
    const timeMatch = text.match(/\b(\d{1,2})(:\d{2})?\s*(am|pm|AM|PM)?\s*(-|to|–)\s*(\d{1,2})(:\d{2})?\s*(am|pm|AM|PM)?/i);
    const singleTimeMatch = text.match(/\bat\s+(\d{1,2})(:\d{2})?\s*(am|pm|AM|PM)?/i);

    if (timeMatch) {
        let sh = parseInt(timeMatch[1]); let sm = timeMatch[2] ? parseInt(timeMatch[2].slice(1)) : 0;
        const sAmpm = timeMatch[3]; let eh = parseInt(timeMatch[5]); let em = timeMatch[6] ? parseInt(timeMatch[6].slice(1)) : 0;
        const eAmpm = timeMatch[7];
        if (sAmpm && sAmpm.toLowerCase() === 'pm' && sh < 12) sh += 12;
        if (eAmpm && eAmpm.toLowerCase() === 'pm' && eh < 12) eh += 12;
        startTime = padZ(sh) + ':' + padZ(sm);
        endTime   = padZ(eh) + ':' + padZ(em);
        title = text.replace(timeMatch[0], '').trim();
    } else if (singleTimeMatch) {
        let h = parseInt(singleTimeMatch[1]); const m = singleTimeMatch[2] ? parseInt(singleTimeMatch[2].slice(1)) : 0;
        const ampm = singleTimeMatch[3];
        if (ampm && ampm.toLowerCase() === 'pm' && h < 12) h += 12;
        if (ampm && ampm.toLowerCase() === 'am' && h === 12) h = 0;
        startTime = padZ(h) + ':' + padZ(m);
        endTime   = padZ(h + 1) + ':' + padZ(m);
        title = text.replace(singleTimeMatch[0], '').trim();
    }

    // Parse relative dates
    const lower = text.toLowerCase();
    if (lower.includes('tomorrow')) {
        const d = new Date(today); d.setDate(d.getDate()+1); date = toDateStr(d);
        title = title.replace(/tomorrow/i, '').trim();
    } else if (lower.includes('today')) {
        date = toDateStr(today);
        title = title.replace(/today/i, '').trim();
    } else if (lower.match(/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i)) {
        const dayMap = {monday:1,tuesday:2,wednesday:3,thursday:4,friday:5,saturday:6,sunday:0};
        const match = lower.match(/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i);
        if (match) {
            const targetDay = dayMap[match[1].toLowerCase()];
            const d = new Date(today);
            const diff = (targetDay - d.getDay() + 7) % 7 || 7;
            d.setDate(d.getDate() + diff); date = toDateStr(d);
            title = title.replace(match[0], '').trim();
        }
    }

    title = title.replace(/\s+/g, ' ').trim() || 'New Event';
    return { title: title, date: date, start: startTime, end: endTime };
}

// ── Free time finder (#81) ────────────────────────────────────────────────────
function findFreeTime() {
    const dateInput = document.getElementById('freeTimeDateInput');
    const resultDiv = document.getElementById('freeTimeResult');
    if (!dateInput || !resultDiv) return;
    const ds = dateInput.value;
    if (!ds) return;

    const dayEvents = events.filter(function(e) { return e.date === ds && e.start_time && e.end_time; })
        .sort(function(a, b) { return timeToMinutes(a.start_time) - timeToMinutes(b.start_time); });

    const workStart = 8 * 60, workEnd = 18 * 60;
    const busy = dayEvents.map(function(e) {
        return { s: timeToMinutes(e.start_time), e: timeToMinutes(e.end_time) };
    });

    const slots = [];
    let cur = workStart;
    busy.forEach(function(b) {
        if (b.s > cur) slots.push({ s: cur, e: b.s });
        cur = Math.max(cur, b.e);
    });
    if (cur < workEnd) slots.push({ s: cur, e: workEnd });

    if (slots.length === 0) { resultDiv.innerHTML = '<span style="color:#b91c1c;">No free slots found.</span>'; return; }
    resultDiv.innerHTML = slots.map(function(sl) {
        return '🟢 ' + minsToTimeStr(sl.s) + ' – ' + minsToTimeStr(sl.e) + ' (' + (sl.e - sl.s) + 'min)';
    }).join('<br>');

    // Daily time budget bar (#82)
    const totalScheduled = busy.reduce(function(acc, b) { return acc + (b.e - b.s); }, 0);
    const pct = Math.min(100, (totalScheduled / (workEnd - workStart)) * 100);
    resultDiv.innerHTML += '<br><div style="margin-top:6px;font-size:0.78rem;color:#6b7280;">Daily budget: ' + totalScheduled + 'min / 600min</div><div class="time-budget-bar"><div class="time-budget-fill" style="width:' + pct + '%"></div></div>';
}

// ── Statistics dashboard (#83) ────────────────────────────────────────────────
function toggleStatsDashboard() {
    const div = document.getElementById('statsDashboard');
    if (!div) return;
    if (div.style.display === 'none' || !div.style.display) {
        div.style.display = 'block';
        renderStatsDashboard(div);
    } else {
        div.style.display = 'none';
    }
}

function showStatsDashboard() {
    const modal = document.getElementById('statsModal');
    const content = document.getElementById('statsContent');
    if (!modal || !content) return;
    renderStatsDashboard(content);
    modal.style.display = 'flex';
}

function closeStatsModal() {
    const m = document.getElementById('statsModal'); if (m) m.style.display = 'none';
}

function renderStatsDashboard(container) {
    const catCount = {};
    const dayCount = {0:0,1:0,2:0,3:0,4:0,5:0,6:0};
    let totalHrs = 0;
    events.forEach(function(e) {
        const cat = e.category || 'Uncategorized';
        catCount[cat] = (catCount[cat] || 0) + 1;
        const d = new Date(e.date + 'T00:00:00');
        if (!isNaN(d)) dayCount[d.getDay()] = (dayCount[d.getDay()] || 0) + 1;
        if (e.start_time && e.end_time) {
            totalHrs += (timeToMinutes(e.end_time) - timeToMinutes(e.start_time)) / 60;
        }
    });

    const maxCat = Math.max(1, ...Object.values(catCount));
    const dayNames2 = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const maxDay = Math.max(1, ...Object.values(dayCount));

    let html = '<div style="font-size:0.85rem;color:#6b7280;margin-bottom:8px;">Total events: <strong>' + events.length + '</strong> | Total hours: <strong>' + totalHrs.toFixed(1) + 'h</strong></div>';
    html += '<div style="font-weight:bold;font-size:0.82rem;margin-bottom:4px;">By Category:</div>';

    const sortedCats = Object.entries(catCount).sort(function(a,b){return b[1]-a[1];}).slice(0,8);
    sortedCats.forEach(function(kv) {
        const pct = Math.round(kv[1] / maxCat * 100);
        html += '<div class="stats-bar-row"><div class="stats-bar-label" title="'+kv[0]+'">'+kv[0]+'</div><div class="stats-bar-track"><div class="stats-bar-fill" style="width:'+pct+'%"></div></div><div class="stats-bar-count">'+kv[1]+'</div></div>';
    });

    html += '<div style="font-weight:bold;font-size:0.82rem;margin:8px 0 4px;">Busiest Days:</div>';
    dayNames2.forEach(function(name, i) {
        const count = dayCount[i] || 0;
        const pct = Math.round(count / maxDay * 100);
        html += '<div class="stats-bar-row"><div class="stats-bar-label">'+name+'</div><div class="stats-bar-track"><div class="stats-bar-fill" style="width:'+pct+'%"></div></div><div class="stats-bar-count">'+count+'</div></div>';
    });

    container.innerHTML = html;
}

// ── renderAgenda base implementation ──────────────────────────────────────────
// (full implementation appears below, merged with all enhancements)

// ── Weekly digest (#95) ───────────────────────────────────────────────────────
function renderWeeklyDigest() {
    const div = document.getElementById('digestContent');
    if (!div) return;
    const today = new Date();
    const nextWeek = new Date(today); nextWeek.setDate(today.getDate() + 7);
    const todayStr2 = toDateStr(today);
    const nextStr = toDateStr(nextWeek);
    const upcoming = events.filter(function(e) {
        return e.date >= todayStr2 && e.date <= nextStr;
    }).sort(function(a,b) {
        if (a.date !== b.date) return a.date < b.date ? -1 : 1;
        return timeToMinutes(a.start_time) - timeToMinutes(b.start_time);
    }).slice(0, 10);

    if (upcoming.length === 0) { div.innerHTML = '<span style="color:#9ca3af;font-size:0.78rem;">No events in the next 7 days.</span>'; return; }

    div.innerHTML = upcoming.map(function(e) {
        const d = new Date(e.date + 'T00:00:00');
        const dayLabel = d.toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric'});
        return '<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;">'
            + '<div style="font-weight:bold;color:' + (e.color || '#6B82F6') + ';font-size:0.8rem;">' + (e.title.split(' - ')[0] || e.title) + '</div>'
            + '<div style="color:#9ca3af;">' + dayLabel + (e.start_time ? ' ' + formatTime(e.start_time) : '') + '</div>'
            + '</div>';
    }).join('');
}

// ── Weekly summary report (#90) ───────────────────────────────────────────────
function showWeeklySummary() {
    const modal = document.getElementById('weeklySummaryModal');
    const content = document.getElementById('weeklySummaryContent');
    if (!modal || !content) return;

    const today = new Date();
    const weekStart = new Date(today); weekStart.setDate(today.getDate() - today.getDay());
    const weekEnd   = new Date(weekStart); weekEnd.setDate(weekStart.getDate() + 6);
    const wsStr = toDateStr(weekStart), weStr = toDateStr(weekEnd);

    const weekEvents = events.filter(function(e) { return e.date >= wsStr && e.date <= weStr; })
        .sort(function(a,b) {
            if (a.date !== b.date) return a.date < b.date ? -1 : 1;
            return timeToMinutes(a.start_time) - timeToMinutes(b.start_time);
        });

    let totalMins = 0;
    weekEvents.forEach(function(e) {
        if (e.start_time && e.end_time) totalMins += timeToMinutes(e.end_time) - timeToMinutes(e.start_time);
    });

    let html = '<div style="font-weight:bold;font-size:1rem;margin-bottom:1rem;">Week of ' + formatDate(wsStr) + ' – ' + formatDate(weStr) + '</div>';
    html += '<div style="margin-bottom:0.5rem;color:#6b7280;">Total events: <strong>' + weekEvents.length + '</strong> | Total scheduled: <strong>' + Math.round(totalMins/60*10)/10 + 'h</strong></div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
    html += '<thead><tr style="background:#f9fafb;"><th style="padding:6px;text-align:left;border-bottom:2px solid #e5e7eb;">Date</th><th style="padding:6px;text-align:left;border-bottom:2px solid #e5e7eb;">Time</th><th style="padding:6px;text-align:left;border-bottom:2px solid #e5e7eb;">Event</th><th style="padding:6px;text-align:left;border-bottom:2px solid #e5e7eb;">Category</th></tr></thead><tbody>';
    weekEvents.forEach(function(e) {
        const d = new Date(e.date + 'T00:00:00');
        html += '<tr style="border-bottom:1px solid #e5e7eb;">'
            + '<td style="padding:6px;">' + d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'}) + '</td>'
            + '<td style="padding:6px;">' + (e.start_time ? formatTime(e.start_time) : '—') + '</td>'
            + '<td style="padding:6px;"><span style="color:' + (e.color||'var(--primary)') + ';font-weight:bold;">●</span> ' + (e.title.split(' - ')[0]||e.title) + '</td>'
            + '<td style="padding:6px;">' + (e.category||'') + '</td></tr>';
    });
    html += '</tbody></table>';
    content.innerHTML = html;
    modal.style.display = 'flex';
}
function closeWeeklySummary() {
    const m = document.getElementById('weeklySummaryModal'); if (m) m.style.display = 'none';
}

// ── Habit tracker (#89) ───────────────────────────────────────────────────────
function showHabitTracker() {
    const modal = document.getElementById('habitModal');
    const content = document.getElementById('habitContent');
    if (!modal || !content) return;

    const today = new Date();
    const weekStart = new Date(today); weekStart.setDate(today.getDate() - 6);
    const dailyEvents = events.filter(function(e) {
        return e.recurrence === 'daily' || e.recurrence === 'weekly';
    });
    const uniqueHabits = {};
    dailyEvents.forEach(function(e) { uniqueHabits[e.id] = e; });

    let html = '';
    const days = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date(today); d.setDate(today.getDate() - 6 + i);
        days.push(toDateStr(d));
    }

    html += '<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">';
    html += '<thead><tr><th style="text-align:left;padding:4px;">Habit</th>' + days.map(function(ds) {
        const d = new Date(ds+'T00:00:00');
        return '<th style="padding:2px 4px;text-align:center;">' + d.toLocaleDateString('en-US',{weekday:'short'}) + '<br><span style="font-weight:normal;">' + d.getDate() + '</span></th>';
    }).join('') + '</tr></thead><tbody>';

    const allDayEventsForRange = events.filter(function(e) {
        return days.includes(e.date) && e.recurrence === 'daily';
    });
    const habitTitles = {};
    allDayEventsForRange.forEach(function(e) { habitTitles[e.title] = true; });

    Object.keys(habitTitles).forEach(function(title) {
        html += '<tr><td style="padding:4px;font-weight:600;">' + title.split(' - ')[0] + '</td>';
        days.forEach(function(ds) {
            const done = events.some(function(e) { return e.title === title && e.date === ds; });
            html += '<td style="text-align:center;padding:4px;">' + (done ? '✅' : '⬜') + '</td>';
        });
        html += '</tr>';
    });

    if (Object.keys(habitTitles).length === 0) html = '<div style="color:#9ca3af;">No daily recurring events found.</div>';
    else html += '</tbody></table>';

    content.innerHTML = html;
    modal.style.display = 'flex';
}
function closeHabitModal() {
    const m = document.getElementById('habitModal'); if (m) m.style.display = 'none';
}

// ── Public holidays display (#78) ─────────────────────────────────────────────
function showPublicHolidays() {
    if (typeof publicHolidays === 'undefined') return;
    const sorted = Object.entries(publicHolidays).sort(function(a,b) { return a[0] < b[0] ? -1 : 1; });
    let msg = 'Public Holidays:\n\n';
    sorted.forEach(function(kv) { msg += kv[0] + ': ' + kv[1] + '\n'; });
    alert(msg);
}

// ── In-app notification center (#93) ──────────────────────────────────────────

function renderNotifCenter() {
    const list = document.getElementById('notifList');
    if (!list) return;
    if (notifHistory.length === 0) { list.textContent = 'No notifications.'; return; }
    list.innerHTML = notifHistory.slice(0,20).map(function(n) {
        return '<div style="padding:6px 0;border-bottom:1px solid #f3f4f6;">'
            + '<div style="font-weight:600;">' + (n.title || 'Event') + '</div>'
            + '<div style="color:#9ca3af;font-size:0.75rem;">' + n.time + '</div>'
            + (n.snooze ? '' : '<button class="snooze-btn" onclick="snoozeNotif(\'' + n.id + '\')">Snooze 10min</button>')
            + '</div>';
    }).join('');
}

function addNotifToHistory(title, time, id) {
    notifHistory.unshift({ title: title, time: time, id: id, snooze: false });
    if (notifHistory.length > 50) notifHistory.pop();
    try { localStorage.setItem('notifHistory', JSON.stringify(notifHistory)); } catch(e) {}
    updateNotifCount();
}

function clearNotifications() {
    notifHistory = [];
    try { localStorage.setItem('notifHistory', '[]'); } catch(e) {}
    updateNotifCount();
    renderNotifCenter();
}

function updateNotifCount() {
    const badge = document.getElementById('notifCount');
    if (!badge) return;
    const count = notifHistory.length;
    badge.textContent = count;
    badge.style.display = count > 0 ? 'inline-block' : 'none';
}

// ── Snooze notification (#92) ──────────────────────────────────────────────────
const snoozedNotifs = {};
function snoozeNotif(id) {
    snoozedNotifs[id] = Date.now() + 10 * 60 * 1000;
    const n = notifHistory.find(function(n) { return n.id === id; });
    if (n) n.snooze = true;
    try { localStorage.setItem('notifHistory', JSON.stringify(notifHistory)); } catch(e) {}
    renderNotifCenter();
    showNotifToast('Snoozed for 10 minutes.');
}

// ── Show notification toast (helper) ─────────────────────────────────────────
function showNotifToast(msg) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1f2937;color:white;padding:10px 18px;border-radius:8px;font-size:0.9rem;z-index:99999;animation:fadeInUp 0.2s ease;';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3000);
}

// ── Overdue re-notification (#97) ─────────────────────────────────────────────
function checkOverdueRenotify() {
    const now = new Date();
    const td = todayStr();
    events.forEach(function(e) {
        if (!e.start_time || e.date > td) return;
        const key = 'overdue_' + e.id + '_' + e.date;
        const lastNotif = parseInt(localStorage.getItem(key) || '0', 10);
        if (Date.now() - lastNotif > 30 * 60 * 1000) {
            localStorage.setItem(key, Date.now());
            if (isOverdue(e)) {
                addNotifToHistory('⚠️ Overdue: ' + e.title, new Date().toLocaleTimeString(), key);
            }
        }
    });
}

// ── Holiday notification on day-of (#98) ──────────────────────────────────────
function checkHolidayNotification() {
    if (typeof publicHolidays === 'undefined') return;
    const td = todayStr();
    if (publicHolidays[td]) {
        addNotifToHistory('🎉 Holiday: ' + publicHolidays[td], 'Today', 'holiday_' + td);
        showNotifToast('🎉 Today is ' + publicHolidays[td] + '!');
    }
}

// ── checkNotifications: quiet hours (#94), notifications with history (#93), custom msg (#96) ──
function checkNotifications() {
    // Quiet hours check (#94)
    const quietStart = getSetting('quietStart', '22:00');
    const quietEnd   = getSetting('quietEnd',   '08:00');
    const now = new Date();
    const curMins = now.getHours() * 60 + now.getMinutes();
    const qs = timeToMinutes(quietStart), qe = timeToMinutes(quietEnd);
    let isQuiet = false;
    if (qs > qe) { isQuiet = curMins >= qs || curMins < qe; }
    else { isQuiet = curMins >= qs && curMins < qe; }
    if (isQuiet) return;

    // Base notification logic with history tracking
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
    const nowMs = now.getTime(); const in15Ms = nowMs + 15 * 60 * 1000;
    events.forEach(function(event) {
        if (!event.start_time) return;
        const key = event.date + '_' + event.id;
        if (notifiedEvents.has(key)) return;
        // Check snooze (#92)
        if (snoozedNotifs[key] && Date.now() < snoozedNotifs[key]) return;
        const evMs = new Date(event.date + 'T' + event.start_time).getTime();
        if (evMs >= nowMs && evMs <= in15Ms) {
            notifiedEvents.add(key);
            const msg = event.reminders ? (JSON.parse(event.reminders||'[]')[0] || {}).message || ('Starts at ' + formatTime(event.start_time)) : 'Starts at ' + formatTime(event.start_time) + ' on ' + event.date;
            try {
                new Notification(event.title, { body: msg });
                addNotifToHistory(event.title, formatTime(event.start_time) + ' on ' + event.date, key);
            } catch(err) {}
        }
    });
}

// ── Multiple reminders per event (#91) ────────────────────────────────────────
function checkEventReminders() {
    const now = new Date(); const nowMs = now.getTime();
    events.forEach(function(event) {
        if (!event.reminders || !event.start_time || !event.date) return;
        let reminders = [];
        try { reminders = JSON.parse(event.reminders || '[]'); } catch(e) {}
        reminders.forEach(function(r, ri) {
            const minsBefore = r.min || 15;
            const key = 'reminder_' + event.id + '_' + event.date + '_' + ri;
            if (notifiedEvents.has(key)) return;
            if (snoozedNotifs[key] && Date.now() < snoozedNotifs[key]) return;
            const evMs = new Date(event.date + 'T' + event.start_time).getTime();
            const alertAt = evMs - minsBefore * 60 * 1000;
            if (nowMs >= alertAt && nowMs <= evMs) {
                notifiedEvents.add(key);
                const msg = r.message || ('Starts in ' + minsBefore + ' min');
                try { new Notification(event.title, { body: msg }); } catch(err) {}
                addNotifToHistory(event.title, msg, key);
            }
        });
    });
}

// ── Pomodoro timer (#87) ──────────────────────────────────────────────────────
function showPomodoroTimer() {
    const m = document.getElementById('pomodoroModal'); if (m) m.style.display = 'flex';
    updatePomodoroDisplay();
}
function closePomodoroModal() {
    const m = document.getElementById('pomodoroModal'); if (m) m.style.display = 'none';
}
function startPomodoro() {
    if (pomodoroRunning) return;
    pomodoroRunning = true;
    pomodoroInterval = setInterval(function() {
        pomodoroTimeLeft--;
        updatePomodoroDisplay();
        if (pomodoroTimeLeft <= 0) {
            clearInterval(pomodoroInterval);
            pomodoroRunning = false;
            if (pomodoroSession === 'work') {
                pomodoroCount++;
                pomodoroSession = 'break';
                pomodoroTimeLeft = 5 * 60;
                showNotifToast('🍅 Pomodoro done! Take a 5-min break.');
                try { new Notification('Pomodoro', {body:'Time for a break!'}); } catch(e) {}
            } else {
                pomodoroSession = 'work';
                pomodoroTimeLeft = 25 * 60;
                showNotifToast('⏰ Break over! Back to work.');
            }
            updatePomodoroDisplay();
        }
    }, 1000);
}
function pausePomodoro() {
    clearInterval(pomodoroInterval); pomodoroRunning = false;
}
function resetPomodoro() {
    clearInterval(pomodoroInterval); pomodoroRunning = false;
    pomodoroSession = 'work'; pomodoroTimeLeft = 25 * 60;
    updatePomodoroDisplay();
}
function updatePomodoroDisplay() {
    const display = document.getElementById('pomodoroDisplay');
    const status  = document.getElementById('pomodoroStatus');
    const count   = document.getElementById('pomodoroCount');
    if (display) {
        const m = Math.floor(pomodoroTimeLeft / 60), s = pomodoroTimeLeft % 60;
        display.textContent = padZ(m) + ':' + padZ(s);
        display.className = pomodoroSession === 'work' ? 'pomodoro-work' : 'pomodoro-break';
    }
    if (status) status.textContent = pomodoroSession === 'work' ? '🍅 Work session' : '☕ Break time';
    if (count) count.textContent = pomodoroCount;
}

// ── PDF export (#77) ──────────────────────────────────────────────────────────
function exportPDF() {
    window.print();
}

// ── Create calendar modal (#51) ───────────────────────────────────────────────
function showCreateCalendarModal() {
    const m = document.getElementById('createCalendarModal'); if (m) m.style.display = 'flex';
}
function closeCreateCalendarModal() {
    const m = document.getElementById('createCalendarModal'); if (m) m.style.display = 'none';
}

// ── Event comments (#63) ──────────────────────────────────────────────────────


// ── Event history (#35) ───────────────────────────────────────────────────────
function loadEventHistory(eventId) {
    const div = document.getElementById('historyContent');
    if (!div || !eventId) { if(div) div.innerHTML = '<span style="color:#9ca3af;">Save event first to see history.</span>'; return; }
    fetch('ajax.php?action=get_history&event_id=' + eventId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && data.history) {
                div.innerHTML = data.history.length === 0 ? '<span style="color:#9ca3af;">No history yet.</span>' :
                    data.history.map(function(h) {
                        let snap = {};
                        try { snap = JSON.parse(h.snapshot); } catch(e) {}
                        return '<div style="padding:4px 0;border-bottom:1px solid #f3f4f6;">'
                            + '<div style="font-weight:bold;font-size:0.8rem;">v' + (h.version||'') + ' — ' + h.changed_at + '</div>'
                            + '<div style="color:#6b7280;font-size:0.78rem;">' + (snap.course_name||'') + ' ' + (snap.start_date||'') + '</div>'
                            + '</div>';
                    }).join('');
            }
        }).catch(function() { div.innerHTML = '<span style="color:#9ca3af;">Could not load history.</span>'; });
}

// ── Modal tab switching ───────────────────────────────────────────────────────

// ── openModalForAdd: base + snap interval, reset sub-tasks ───────────────────
function openModalForAdd(dateString, startTime, endTime) {
    resetSubmitButtons();
    document.getElementById('formAction').value = 'add';
    modalEl.style.display = 'flex';

    const dupBtn = document.getElementById('duplicateBtn');
    if (dupBtn) dupBtn.style.display = 'none';

    // Set snap interval
    const snapMin = parseInt(getSetting('snapMin', '15'), 10);
    const snapSel = document.getElementById('snapMinSelect');
    if (snapSel) snapSel.value = snapMin;

    // Reset fields
    const form = document.getElementById('eventForm');
    if (form) form.reset();

    const idEl = document.getElementById('eventID'); if (idEl) idEl.value = '';
    const sdEl = document.getElementById('startDate'); if (sdEl) sdEl.value = dateString || todayStr();
    const edEl = document.getElementById('endDate'); if (edEl) edEl.value = dateString || todayStr();
    const stEl = document.getElementById('startTime'); if (stEl) stEl.value = startTime || '';
    const etEl = document.getElementById('endTime'); if (etEl) etEl.value = endTime || '';
    const recEl = document.getElementById('recurrenceSelect'); if (recEl) recEl.value = 'none';
    const recEndWrap = document.getElementById('recurrenceEndWrapper'); if (recEndWrap) recEndWrap.style.display = 'none';

    // Set default calendar
    const defaultCal = getSetting('defaultCalendar', '');
    if (defaultCal) {
        const calSel = document.getElementById('eventCalendar');
        if (calSel) calSel.value = defaultCal;
    }

    switchModalTab('details');
    notesPreviewVisible = false;
    const preview = document.getElementById('notesPreview');
    if (preview) preview.style.display = 'none';

    // Reset extra fields
    ['eventLocation','eventTags','eventAttendees','eventZoomUrl','eventReminders','eventDeadline'].forEach(function(id) {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    const ecap = document.getElementById('eventCapacity'); if (ecap) ecap.value = '';
    const eas = document.getElementById('eventActualStart'); if (eas) eas.value = '';
    const eae = document.getElementById('eventActualEnd'); if (eae) eae.value = '';
    const esub = document.getElementById('eventSubtasks'); if (esub) esub.value = '';
    const erel = document.getElementById('eventRelatedIds'); if (erel) erel.value = '';
    const relList = document.getElementById('relatedEventsList'); if (relList) relList.innerHTML = '';
    updateLocationLink(); updateDeadlineCountdown();
    loadTemplateOptions();
}

// ── handleEventSelection placeholder defined below ───────────────────────────

// ── renderMonthly: base + multi-day event bars (#1) ──────────────────────────
function renderMonthly(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = 'monthly-view';

    const today    = new Date();
    const year     = date.getFullYear();
    const month    = date.getMonth();
    const firstDay = parseInt(getSetting('firstDay', '0'), 10);

    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    monthYearEl.textContent = monthNames[month] + ' ' + year;

    const totalDays = new Date(year, month + 1, 0).getDate();
    const rawFirst  = new Date(year, month, 1).getDay();
    const offset    = (rawFirst - firstDay + 7) % 7;

    // Day-name header
    const weekDays   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const headerRow  = document.createElement('div');
    headerRow.className = 'calendar-header';
    for (let i = 0; i < 7; i++) {
        const dn = document.createElement('div');
        dn.className = 'day-name';
        dn.textContent = weekDays[(firstDay + i) % 7];
        headerRow.appendChild(dn);
    }
    calendarEl.appendChild(headerRow);

    const grid = document.createElement('div');
    grid.className = 'calendar-grid';

    // Empty cells before first day
    for (let i = 0; i < offset; i++) {
        const empty = document.createElement('div');
        empty.className = 'day empty';
        grid.appendChild(empty);
    }

    // Day cells
    for (let day = 1; day <= totalDays; day++) {
        const ds      = year + '-' + padZ(month + 1) + '-' + padZ(day);
        const isToday = ds === toDateStr(today);
        const cell    = createDayCell(ds, day, isToday, month, year);
        grid.appendChild(cell);
    }

    calendarEl.appendChild(grid);

    const startStr = year + '-' + padZ(month + 1) + '-01';
    const endStr   = year + '-' + padZ(month + 1) + '-' + padZ(totalDays);
    fetchRange(startStr, endStr);

    // Multi-day event bars (#1)
    const multiDayEvents = [];
    const seenIds = {};
    visibleEvents().forEach(function(e) {
        if (seenIds[e.id]) return;
        if (e.start !== e.end && e.start && e.end) {
            seenIds[e.id] = true;
            if (e.start <= endStr && e.end >= startStr) {
                multiDayEvents.push(e);
            }
        }
    });

    const cells = calendarEl.querySelectorAll('.day:not(.empty)');
    multiDayEvents.forEach(function(event) {
        const clampedStart = event.start < startStr ? startStr : event.start;
        const clampedEnd   = event.end   > endStr   ? endStr   : event.end;
        const startDay = parseInt(clampedStart.split('-')[2], 10);
        const endDay   = parseInt(clampedEnd.split('-')[2], 10);
        for (let d = startDay; d <= endDay; d++) {
            const cellIdx = d - 1;
            if (cellIdx < 0 || cellIdx >= cells.length) continue;
            const cell = cells[cellIdx];
            const bar = document.createElement('div');
            bar.className = 'multiday-bar';
            if (d === startDay && d === endDay) bar.classList.add('multiday-bar-single');
            else if (d === startDay) bar.classList.add('multiday-bar-start');
            else if (d === endDay) bar.classList.add('multiday-bar-end');
            else bar.classList.add('multiday-bar-middle');
            bar.style.backgroundColor = event.color || '#6B82F6';
            if (d === startDay) bar.textContent = event.title.split(' - ')[0];
            bar.title = event.title;
            bar.onclick = function(ev) { ev.stopPropagation(); openModalForEdit([event]); };
            cell.appendChild(bar);
        }
    });
}

// ── Hover preview card on monthly pills (#19) ─────────────────────────────────
let hoverPreviewTimeout = null;
let hoverPreviewEl = null;

function showEventHoverPreview(event, x, y) {
    hideEventHoverPreview();
    hoverPreviewEl = document.createElement('div');
    hoverPreviewEl.className = 'event-hover-preview';
    hoverPreviewEl.innerHTML = '<h4>' + (event.title || '') + '</h4>'
        + (event.start_time ? '<div>🕐 ' + formatTime(event.start_time) + ' – ' + formatTime(event.end_time) + '</div>' : '')
        + (event.location ? '<div>📍 ' + event.location + '</div>' : '')
        + (event.category ? '<div>🏷️ ' + event.category + '</div>' : '')
        + (event.notes ? '<div style="color:#6b7280;margin-top:4px;">' + (event.notes.length > 80 ? event.notes.slice(0,80)+'…' : event.notes) + '</div>' : '');
    hoverPreviewEl.style.left = (x + 12) + 'px';
    hoverPreviewEl.style.top  = (y + 12) + 'px';
    document.body.appendChild(hoverPreviewEl);
}

function hideEventHoverPreview() {
    if (hoverPreviewEl) { hoverPreviewEl.remove(); hoverPreviewEl = null; }
    clearTimeout(hoverPreviewTimeout);
}

// Patch createDayCell events to show hover preview
document.addEventListener('mouseover', function(e) {
    const ev = e.target.closest('.event');
    if (!ev) return;
    const ds = ev.closest('.day')?.querySelector('.date-number')?.textContent;
    if (!ds) return;
    const evId = ev.querySelector('[data-event-id]') || ev;
    // Find event by position
    hoverPreviewTimeout = setTimeout(function() {
        // Use tooltip logic instead
        if (ev._hoverEvent) showEventHoverPreview(ev._hoverEvent, e.clientX, e.clientY);
    }, 400);
});

document.addEventListener('mouseout', function(e) {
    if (e.target.closest('.event')) hideEventHoverPreview();
});

// ── Save webhook via AJAX (#69) ────────────────────────────────────────────────
function saveWebhook() {
    const urlInput = document.getElementById('webhookUrlInput');
    if (!urlInput || !urlInput.value.trim()) { showNotifToast('Enter a webhook URL first.'); return; }
    const body = new URLSearchParams();
    body.set('action', 'save_webhook');
    body.set('url', urlInput.value.trim());
    fetch('ajax.php', {method:'POST', body:body})
        .then(function(r) { return r.json(); })
        .then(function(d) { showNotifToast(d.ok ? 'Webhook saved!' : 'Failed to save webhook.'); });
}

// ── Bulk reschedule (#39) ─────────────────────────────────────────────────────
function applyBulkReschedule() {
    if (selectedEventIds.size === 0) return;
    const shift = parseInt(document.getElementById('bulkShiftDays').value || '0', 10);
    if (shift === 0) { showNotifToast('Enter a shift in days.'); return; }
    showConfirmModal('Shift ' + selectedEventIds.size + ' event(s) by ' + shift + ' day(s)?', function() {
        showSpinner();
        const body = new URLSearchParams();
        body.set('action', 'bulk_reschedule');
        body.set('ids', Array.from(selectedEventIds).join(','));
        body.set('shift_days', shift);
        fetch('index.php', {method:'POST', body:body})
            .then(function() { hideSpinner(); clearBulkSelect(); location.reload(); });
    });
}

// ── Event color independently of category (#40) ───────────────────────────────
// Already works independently via the color picker. Ensure it overrides category color.
// This is confirmed: event.color is always used over any category default.

// ── Pinch-to-zoom on mobile time grid (#13) ───────────────────────────────────
document.addEventListener('touchstart', function(e) {
    if (e.touches.length === 2) {
        const dx = e.touches[0].clientX - e.touches[1].clientX;
        const dy = e.touches[0].clientY - e.touches[1].clientY;
        pinchStartDist = Math.sqrt(dx*dx + dy*dy);
        pinchBaseScale = parseFloat(document.body.style.getPropertyValue('--time-grid-scale') || '1');
    }
}, {passive: true});

document.addEventListener('touchmove', function(e) {
    if (e.touches.length === 2 && pinchStartDist) {
        const dx = e.touches[0].clientX - e.touches[1].clientX;
        const dy = e.touches[0].clientY - e.touches[1].clientY;
        const dist = Math.sqrt(dx*dx + dy*dy);
        const scale = Math.min(2.5, Math.max(0.5, pinchBaseScale * (dist / pinchStartDist)));
        const body = document.querySelector('.time-grid-body');
        if (body) body.style.setProperty('--hour-height', Math.round(60 * scale) + 'px');
    }
}, {passive: true});

document.addEventListener('touchend', function(e) {
    if (e.touches.length < 2) pinchStartDist = null;
}, {passive: true});

// ── Event form: add event listeners for new fields ────────────────────────────
const eventFormEl2 = document.getElementById('eventForm');
if (eventFormEl2) {
    const deadlineInput = eventFormEl2.querySelector('[name="deadline"]');
    if (deadlineInput) deadlineInput.addEventListener('change', updateDeadlineCountdown);
    const zoomInput = eventFormEl2.querySelector('[name="zoom_url"]');
    if (zoomInput) zoomInput.addEventListener('input', updateZoomBtn);
    const actualStartInput = eventFormEl2.querySelector('[name="actual_start"]');
    if (actualStartInput) actualStartInput.addEventListener('change', updateActualVsPlanned);
    const actualEndInput = eventFormEl2.querySelector('[name="actual_end"]');
    if (actualEndInput) actualEndInput.addEventListener('change', updateActualVsPlanned);
    const subtasksInput = eventFormEl2.querySelector('[name="subtasks"]');
    if (subtasksInput) subtasksInput.addEventListener('input', renderSubtasksChecklist);
}

// ── Extended keyboard shortcuts ───────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
    if (e.key === 'Escape') {
        closeShortcutsModal();
        closePomodoroModal();
        closeDuplicateDialog();
        closeMoveDialog();
        closeQuickAddModal();
        closeCreateCalendarModal();
        closeWeeklySummary();
        closeStatsModal();
        closeHabitModal();
    }
});

// ── Set edit scope for "edit future" in form submission (#21) ─────────────────
const eventFormEl3 = document.getElementById('eventForm');
if (eventFormEl3) {
    eventFormEl3.addEventListener('submit', function() {
        const scope = document.querySelector('input[name="edit_scope"]:checked');
        if (scope && scope.value === 'future') {
            document.getElementById('formAction').value = 'edit_future';
        }
    });
}


// ════════════════════════════════════════════════════════════════════════════
// MISSING IMPLEMENTATIONS — appended session 2
// ════════════════════════════════════════════════════════════════════════════

// ── #7  Sticky current-time indicator ─────────────────────────────────────────
setInterval(function() {
    var body = document.querySelector('.time-grid-body');
    var container = body ? body.closest('.time-grid-container') : null;
    if (body && container) updateTimeIndicator(body, container);
}, 30000);

// ── createTimedEvent: base + flip card (#16) ─────────────────────────────────
function createTimedEvent(event, hourRange) {
    const range = hourRange || { start: 0, end: 24 };
    if (!event.start_time) return null;

    const startMins = timeToMinutes(event.start_time);
    const endMins   = timeToMinutes(event.end_time || event.start_time) || startMins + 60;
    const durationMins = Math.max(15, endMins - startMins);

    const el = document.createElement('div');
    el.className = 'timed-event';
    if (isOverdue(event)) el.classList.add('event-overdue');
    if (bulkSelectActive && selectedEventIds.has(String(event.id))) el.classList.add('selected-event');

    el.style.cssText = 'position:absolute;left:0;right:0;overflow:hidden;border-radius:4px;padding:2px 4px;font-size:0.78rem;cursor:pointer;box-sizing:border-box;z-index:2;';
    el.style.top    = (startMins - range.start * 60) + 'px';
    el.style.height = durationMins + 'px';
    el.style.background = event.color || '#6B82F6';
    el.style.color = 'white';
    el._hoverEvent = event;

    // Priority dot
    const priDot = createPriorityDot(event.priority || 1);
    priDot.style.cssText += 'float:left;margin-right:3px;margin-top:2px;';
    el.appendChild(priDot);

    // Title
    const titleEl = document.createElement('div');
    titleEl.style.cssText = 'font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
    titleEl.textContent = event.title.split(' - ')[0];
    el.appendChild(titleEl);

    // Time
    if (durationMins >= 30) {
        const timeEl = document.createElement('div');
        timeEl.style.cssText = 'font-size:0.72rem;opacity:0.9;';
        timeEl.textContent = formatTime(event.start_time) + ' \u2013 ' + formatTime(event.end_time);
        el.appendChild(timeEl);
    }

    // Resize handle
    const resizeHandle = document.createElement('div');
    resizeHandle.className = 'resize-handle';
    resizeHandle.style.cssText = 'position:absolute;bottom:0;left:0;right:0;height:6px;cursor:s-resize;background:rgba(0,0,0,0.15);border-radius:0 0 4px 4px;';
    resizeHandle.addEventListener('mousedown', function(e) {
        e.stopPropagation();
        e.preventDefault();
        resizeState = {
            el: el,
            startY: e.clientY,
            origHeight: parseFloat(el.style.height) || durationMins,
            origEndMins: endMins,
            eventId: event.id,
            origEvent: event,
            range: range
        };
    });
    el.appendChild(resizeHandle);

    // Drag
    el.addEventListener('mousedown', function(e) {
        if (e.target === resizeHandle) return;
        if (e.button !== 0) return;
        wasDrag = false;
        dragState = {
            el: el,
            startX: e.clientX,
            startY: e.clientY,
            startMins: startMins,
            eventId: event.id,
            origEvent: event
        };
        el.style.opacity = '0.8';
    });

    el.onclick = function(e) {
        if (wasDrag) return;
        if (bulkSelectActive) { toggleEventSelection(event.id, el); return; }
        openModalForEdit([event]);
    };
    el.oncontextmenu = function(e) { e.preventDefault(); showContextMenu(event, e.clientX, e.clientY); };

    // Tooltip
    el.addEventListener('mouseenter', function(e) { showTooltip(event, e); });
    el.addEventListener('mouseleave', hideTooltip);

    // Flip card for tall events with notes/location (#16)
    var h = parseFloat(el.style.height) || 0;
    if (h >= 60 && (event.notes || event.location)) {
        el.classList.add('event-flip-container');
        var inner = document.createElement('div');
        inner.className = 'event-flip-inner';
        inner.style.cssText = 'position:relative;width:100%;height:100%;transform-style:preserve-3d;transition:transform 0.4s;';
        var front = document.createElement('div');
        front.className = 'event-flip-front';
        front.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;overflow:hidden;backface-visibility:hidden;';
        while (el.firstChild && el.firstChild !== inner) front.appendChild(el.firstChild);
        var back = document.createElement('div');
        back.className = 'event-flip-back';
        back.style.cssText = 'backface-visibility:hidden;transform:rotateY(180deg);';
        back.textContent = event.notes ? event.notes.slice(0, 120) : ('📍 ' + (event.location || 'No info'));
        inner.appendChild(front);
        inner.appendChild(back);
        el.appendChild(inner);
        el.style.perspective = '600px';
        el.style.overflow = 'hidden';
        el.addEventListener('mouseenter', function() { inner.style.transform = 'rotateY(180deg)'; });
        el.addEventListener('mouseleave', function() { inner.style.transform = ''; });
    }

    return el;
}

// ── createDayCell: base + hover preview binding (#19) ────────────────────────
function createDayCell(ds, dayNum, isToday, cellMonth, cellYear) {
    const cell = document.createElement('div');
    cell.className = 'day' + (isToday ? ' today' : '');
    cell.dataset.date = ds;

    // Day number
    const dateNumEl = document.createElement('div');
    dateNumEl.className = 'date-number';
    dateNumEl.textContent = dayNum;
    cell.appendChild(dateNumEl);

    // Holiday badge
    if (typeof publicHolidays !== 'undefined' && publicHolidays[ds]) {
        const hBadge = document.createElement('div');
        hBadge.style.cssText = 'font-size:9px;color:#b91c1c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        hBadge.textContent = '🎉 ' + publicHolidays[ds];
        hBadge.title = publicHolidays[ds];
        cell.appendChild(hBadge);
    }

    // Events for this day
    const dayEvents = visibleEvents().filter(function(e) { return e.date === ds; })
        .sort(function(a, b) { return timeToMinutes(a.start_time) - timeToMinutes(b.start_time); });

    const maxVisible = 3;
    dayEvents.slice(0, maxVisible).forEach(function(event, idx) {
        const evEl = document.createElement('div');
        evEl.className = 'event';
        if (isOverdue(event)) evEl.classList.add('event-overdue');
        if (bulkSelectActive && selectedEventIds.has(String(event.id))) evEl.classList.add('selected-event');
        evEl.style.background = event.color || '#6B82F6';
        evEl.style.color = 'white';
        evEl.title = event.title + (event.start_time ? ' (' + formatTime(event.start_time) + ')' : '');

        const priDot = createPriorityDot(event.priority || 1);
        priDot.style.cssText += 'display:inline-block;margin-right:3px;';
        evEl._hoverEvent = event;

        const titleSpan = document.createElement('span');
        titleSpan.textContent = (event.start_time ? formatTime(event.start_time) + ' ' : '') + event.title.split(' - ')[0];

        evEl.appendChild(priDot);
        evEl.appendChild(titleSpan);

        evEl.onclick = function(e) {
            e.stopPropagation();
            if (bulkSelectActive) { toggleEventSelection(event.id, evEl); return; }
            openModalForEdit([event]);
        };
        evEl.oncontextmenu = function(e) { e.preventDefault(); showContextMenu(event, e.clientX, e.clientY); };
        cell.appendChild(evEl);
    });

    if (dayEvents.length > maxVisible) {
        const more = document.createElement('div');
        more.className = 'more-events';
        more.textContent = '+' + (dayEvents.length - maxVisible) + ' more';
        more.onclick = function(e) { e.stopPropagation(); openModalForEdit(dayEvents); };
        cell.appendChild(more);
    }

    // Click cell to add event
    cell.onclick = function(e) {
        if (e.target === cell || e.target === dateNumEl) {
            openModalForAdd(ds);
        }
    };

    // Deadline badge (#85)
    const overdueEvents = dayEvents.filter(function(e) { return e.deadline && e.deadline === ds; });
    if (overdueEvents.length > 0) {
        const dlBadge = document.createElement('div');
        dlBadge.style.cssText = 'font-size:9px;color:#b91c1c;font-weight:bold;';
        dlBadge.textContent = '⏰ ' + overdueEvents.length + ' deadline' + (overdueEvents.length > 1 ? 's' : '');
        cell.appendChild(dlBadge);
    }

    return cell;
}

// ── #26 RSVP status per attendee ──────────────────────────────────────────────
function renderRsvpDisplay(attendeesStr) {
    var div = document.getElementById('rsvpDisplay');
    if (!div) return;
    if (!attendeesStr || !attendeesStr.trim()) { div.innerHTML = ''; return; }
    var parts = attendeesStr.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
    if (parts.length === 0) { div.innerHTML = ''; return; }
    div.innerHTML = '<div style="font-weight:bold;margin-bottom:4px;font-size:0.78rem;">Attendees RSVP:</div>' +
        parts.map(function(p) {
            var colonIdx = p.lastIndexOf(':');
            var email = p, status = 'pending';
            if (colonIdx > 3) {
                var possibleStatus = p.slice(colonIdx + 1).toLowerCase();
                if (['accepted','declined','tentative','pending'].indexOf(possibleStatus) !== -1) {
                    email = p.slice(0, colonIdx);
                    status = possibleStatus;
                }
            }
            var icons = { accepted: '✅', declined: '❌', tentative: '🟡', pending: '⬜' };
            return '<div class="rsvp-attendee-row">'
                + '<span class="rsvp-dot rsvp-' + status + '"></span>'
                + '<span>' + email.replace(/</g,'&lt;') + '</span>'
                + '<span style="margin-left:auto;font-size:0.7rem;">' + (icons[status] || '⬜') + ' ' + status + '</span>'
                + '</div>';
        }).join('');
}

var attendeesInput2 = document.getElementById('eventAttendees');
if (attendeesInput2) {
    attendeesInput2.addEventListener('input', function() { renderRsvpDisplay(this.value); });
}

// ── #31 Link related events ────────────────────────────────────────────────────
function updateRelatedEventsDisplay() {
    var input = document.getElementById('eventRelatedIds');
    var div   = document.getElementById('relatedEventsList');
    if (!div) return;
    if (!input || !input.value.trim()) { div.innerHTML = ''; return; }
    var ids = input.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    div.innerHTML = '<div style="font-size:0.75rem;color:#6b7280;margin-bottom:2px;">Linked events:</div>'
        + ids.map(function(id) {
            var ev = events.find(function(e) { return String(e.id) === id; });
            return '<span class="related-event-badge" onclick="openRelatedEvent(\'' + id + '\')" title="' + (ev ? ev.title.replace(/"/g,'') : 'Event #'+id) + '">'
                + (ev ? ev.title.split(' - ')[0].slice(0,20) : '#'+id) + '</span>';
        }).join('');
}

function openRelatedEvent(id) {
    var ev = events.find(function(e) { return String(e.id) === String(id); });
    if (ev) { closeModal(); openModalForEdit([ev]); }
}

var relatedInput = document.getElementById('eventRelatedIds');
if (relatedInput) relatedInput.addEventListener('input', updateRelatedEventsDisplay);

// ── #43 Search result highlighting in agenda view ─────────────────────────────
function highlightText(text, query) {
    if (!query || !text) return (text || '');
    var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return text.replace(new RegExp('(' + escaped + ')', 'gi'), '<mark class="search-highlight">$1</mark>');
}

// renderAgenda: full implementation with search highlighting (#43) and workload bar (#84)
function renderAgenda(date) {
    calendarEl.innerHTML = '';
    calendarEl.className = '';

    const rangeDays = 30;
    const startDate = new Date(date);
    startDate.setHours(0, 0, 0, 0);
    const endDate = new Date(startDate);
    endDate.setDate(startDate.getDate() + rangeDays - 1);
    const startStr = toDateStr(startDate);
    const endStr   = toDateStr(endDate);

    monthYearEl.textContent = 'Agenda: ' + formatDate(startStr) + ' \u2013 ' + formatDate(endStr);

    const filtered = visibleEvents().filter(function(e) {
        return e.date >= startStr && e.date <= endStr;
    }).sort(function(a, b) {
        if (a.date !== b.date) return a.date < b.date ? -1 : 1;
        return timeToMinutes(a.start_time) - timeToMinutes(b.start_time);
    });

    if (filtered.length === 0) {
        const empty = createEmptyState('📅', 'No events in this range', 'Try a different date or clear filters.', filterQuery ? function() { filterQuery = ''; renderCalendar(currentDate); } : null);
        calendarEl.appendChild(empty);
        return;
    }

    // Group by date
    const byDate = {};
    filtered.forEach(function(e) {
        if (!byDate[e.date]) byDate[e.date] = [];
        byDate[e.date].push(e);
    });

    const container = document.createElement('div');
    container.className = 'agenda-view';

    Object.keys(byDate).sort().forEach(function(ds) {
        const dayEvents = byDate[ds];
        const d = new Date(ds + 'T00:00:00');
        const isToday = ds === todayStr();

        const group = document.createElement('div');
        group.className = 'agenda-day-group' + (isToday ? ' agenda-today' : '');

        const dayHeader = document.createElement('div');
        dayHeader.className = 'agenda-day-header';
        dayHeader.textContent = d.toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'});

        // Holiday badge
        if (typeof publicHolidays !== 'undefined' && publicHolidays[ds]) {
            const hBadge = document.createElement('span');
            hBadge.style.cssText = 'margin-left:8px;font-size:0.75rem;background:#fee2e2;color:#b91c1c;padding:1px 6px;border-radius:10px;';
            hBadge.textContent = '🎉 ' + publicHolidays[ds];
            dayHeader.appendChild(hBadge);
        }

        // Workload bar (#84)
        const totalMins = dayEvents.reduce(function(acc, e) {
            if (e.start_time && e.end_time) return acc + timeToMinutes(e.end_time) - timeToMinutes(e.start_time);
            return acc;
        }, 0);
        if (totalMins > 0) {
            const workloadPct = Math.min(100, totalMins / 480 * 100); // 8h = 100%
            const wBar = document.createElement('div');
            wBar.style.cssText = 'margin-top:3px;';
            wBar.innerHTML = '<div class="time-budget-bar" title="' + Math.round(totalMins/60*10)/10 + 'h scheduled"><div class="time-budget-fill" style="width:' + workloadPct + '%;background:' + (workloadPct > 80 ? '#ef4444' : 'var(--primary)') + ';"></div></div>';
            dayHeader.appendChild(wBar);
        }

        group.appendChild(dayHeader);

        dayEvents.forEach(function(event) {
            const row = document.createElement('div');
            row.className = 'agenda-event-row';
            if (isOverdue(event)) row.classList.add('event-overdue');
            if (bulkSelectActive && selectedEventIds.has(String(event.id))) row.classList.add('selected-event');

            const dot = document.createElement('div');
            dot.className = 'agenda-event-dot';
            dot.style.background = event.color || '#6B82F6';
            row.appendChild(dot);

            const content = document.createElement('div');
            content.className = 'agenda-event-content';

            const titleEl = document.createElement('div');
            titleEl.className = 'agenda-event-title';
            // Search highlight (#43)
            if (filterQuery && event.title.toLowerCase().indexOf(filterQuery.toLowerCase()) !== -1) {
                titleEl.innerHTML = highlightText(event.title, filterQuery);
            } else {
                titleEl.textContent = event.title;
            }
            content.appendChild(titleEl);

            const parts = [];
            if (event.start_time) parts.push(formatTime(event.start_time) + ' \u2013 ' + formatTime(event.end_time));
            if (event.category) parts.push(event.category);
            if (event.location) parts.push('📍 ' + event.location);
            if (parts.length > 0) {
                const subEl = document.createElement('div');
                subEl.className = 'agenda-event-sub';
                const subText = parts.join(' · ');
                if (filterQuery && subText.toLowerCase().indexOf(filterQuery.toLowerCase()) !== -1) {
                    subEl.innerHTML = highlightText(subText, filterQuery);
                } else {
                    subEl.textContent = subText;
                }
                content.appendChild(subEl);
            }

            row.appendChild(content);

            row.onclick = function(e) {
                if (bulkSelectActive) { toggleEventSelection(event.id, row); return; }
                openModalForEdit([event]);
            };
            row.oncontextmenu = function(e) { e.preventDefault(); showContextMenu(event, e.clientX, e.clientY); };

            group.appendChild(row);
        });

        container.appendChild(group);
    });

    calendarEl.appendChild(container);
    fetchRange(startStr, endStr);
}

// ── #47 No-results empty state is handled in the filterAndRender above ───────

function clearSearch() {
    var sb = document.getElementById('searchBar');
    if (sb) { sb.value = ''; filterAndRender(''); }
}

// ── #54 Default calendar setting ──────────────────────────────────────────────
function setDefaultCalendar(calId) {
    saveSetting('defaultCalendar', String(calId));
    document.querySelectorAll('.cal-default-star').forEach(function(s) { s.remove(); });
    var item = document.querySelector('.cal-item[data-cal-id="' + calId + '"] .cal-name');
    if (item) {
        var star = document.createElement('span');
        star.className = 'cal-default-star';
        star.textContent = ' ★';
        star.title = 'Default calendar';
        item.appendChild(star);
    }
    showNotifToast('Default calendar set.');
}

(function markDefaultCalendar() {
    var defId = getSetting('defaultCalendar', '');
    if (!defId) return;
    var item = document.querySelector('.cal-item[data-cal-id="' + defId + '"] .cal-name');
    if (item && !item.querySelector('.cal-default-star')) {
        var star = document.createElement('span');
        star.className = 'cal-default-star';
        star.textContent = ' ★';
        star.title = 'Default calendar';
        item.appendChild(star);
    }
    var calSel = document.getElementById('eventCalendar');
    if (calSel && defId) calSel.value = defId;
})();

// ── #56 / #71 / #72 ICS URL import ────────────────────────────────────────────
function importIcsFromUrl() {
    var input = document.getElementById('icsUrlInput');
    if (!input || !input.value.trim()) { showNotifToast('Enter an ICS/iCal URL first.'); return; }
    var url = input.value.trim();
    showSpinner();
    var body = new URLSearchParams();
    body.set('action', 'import_ics_url');
    body.set('url', url);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideSpinner();
            if (data.ok) {
                showNotifToast('Imported ' + (data.count || 0) + ' events!');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showNotifToast('Import failed: ' + (data.error || 'unknown error'));
            }
        })
        .catch(function() { hideSpinner(); showNotifToast('Import failed — check URL.'); });
}

// ── #57 / #58 Shared calendar URL + embed iframe ──────────────────────────────
function showShareCalendarModal() {
    var modal = document.getElementById('shareCalendarModal');
    if (!modal) return;
    var token = getSetting('shareToken', '');
    if (!token) {
        token = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        saveSetting('shareToken', token);
    }
    var base = window.location.origin + window.location.pathname.replace('index.php', '');
    var shareUrl = base + 'api.php?action=shared_calendar&token=' + token;
    var embedCode = '<iframe src="' + base + 'api.php?action=embed_calendar&token=' + token + '" width="800" height="600" frameborder="0"></iframe>';
    var urlEl = document.getElementById('shareUrlDisplay');
    var embedEl = document.getElementById('embedCodeDisplay');
    if (urlEl) urlEl.value = shareUrl;
    if (embedEl) embedEl.value = embedCode;
    modal.style.display = 'flex';
}
function closeShareCalendarModal() {
    var m = document.getElementById('shareCalendarModal'); if (m) m.style.display = 'none';
}
function copyShareUrl() {
    var el = document.getElementById('shareUrlDisplay');
    if (el) { el.select(); document.execCommand('copy'); showNotifToast('URL copied!'); }
}
function copyEmbedCode() {
    var el = document.getElementById('embedCodeDisplay');
    if (el) { el.select(); document.execCommand('copy'); showNotifToast('Embed code copied!'); }
}

// ── #60 Archive/hide a calendar ────────────────────────────────────────────────
function archiveCalendar(calId, calName) {
    if (!confirm('Archive calendar "' + calName + '"? It will be hidden.')) return;
    showSpinner();
    var body = new URLSearchParams();
    body.set('action', 'archive_calendar');
    body.set('cal_id', calId);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideSpinner();
            if (data.ok) {
                var item = document.querySelector('.cal-item[data-cal-id="' + calId + '"]');
                if (item) item.remove();
                showNotifToast('Calendar archived.');
            } else {
                showNotifToast('Archive failed.');
            }
        }).catch(function() { hideSpinner(); });
}

document.querySelectorAll('.cal-item').forEach(function(item) {
    var calId = item.dataset.calId;
    var nameEl = item.querySelector('.cal-name');
    var calName = nameEl ? nameEl.textContent.trim() : 'Calendar';
    var archBtn = document.createElement('button');
    archBtn.className = 'cal-archive-btn';
    archBtn.textContent = '⊘';
    archBtn.title = 'Archive calendar';
    archBtn.onclick = function(e) { e.stopPropagation(); archiveCalendar(calId, calName); };
    item.appendChild(archBtn);
    if (nameEl) {
        nameEl.title = 'Double-click to set as default';
        nameEl.ondblclick = function() { setDefaultCalendar(calId); };
    }
});

// ── #65 @mention formatting in comments ───────────────────────────────────────
function formatCommentWithMentions(text) {
    return text
        .replace(/</g, '&lt;')
        .replace(/@(\w+)/g, '<span class="mention-highlight">@$1</span>');
}

// loadComments: fetch and display with @mention formatting (#65)
function loadComments(eventId) {
    var div = document.getElementById('commentsContent');
    if (!div) return;
    if (!eventId) { div.innerHTML = '<span style="color:#9ca3af;">Save event first to comment.</span>'; return; }
    fetch('ajax.php?action=get_comments&event_id=' + eventId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && data.comments) {
                div.innerHTML = data.comments.length === 0
                    ? '<span style="color:#9ca3af;">No comments yet.</span>'
                    : data.comments.map(function(c) {
                        return '<div style="padding:6px 0;border-bottom:1px solid #f3f4f6;">'
                            + '<span style="font-weight:bold;">' + (c.username || 'User') + '</span>'
                            + ' <span style="color:#9ca3af;font-size:0.75rem;">' + c.created_at + '</span>'
                            + '<div>' + formatCommentWithMentions(c.body) + '</div></div>';
                    }).join('');
            }
        }).catch(function() { div.innerHTML = '<span style="color:#9ca3af;">Could not load comments.</span>'; });
}

// submitComment: track @mention notifications (#65)
function submitComment() {
    var textEl = document.getElementById('newCommentText');
    var eventIdEl = document.getElementById('eventID');
    if (!textEl || !textEl.value.trim()) return;
    if (textEl) {
        var mentions = (textEl.value.match(/@\w+/g) || []);
        if (mentions.length > 0) {
            addNotifToHistory('Mentioned ' + mentions.join(', ') + ' in a comment', new Date().toLocaleTimeString(), 'mention_' + Date.now());
        }
    }
    var eventId = eventIdEl ? eventIdEl.value : '';
    if (!eventId) { showNotifToast('Save event first.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'add_comment');
    body.set('event_id', eventId);
    body.set('body', textEl.value);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                textEl.value = '';
                loadComments(eventId);
            } else {
                showNotifToast('Failed to submit comment.');
            }
        }).catch(function() { showNotifToast('Failed to submit comment.'); });
}

// ── #68 Export shareable static HTML page ─────────────────────────────────────
function exportStaticHtml() {
    var vis = visibleEvents();
    var y = currentDate.getFullYear(), m = currentDate.getMonth();
    var monthStart = y + '-' + padZ(m+1) + '-01';
    var monthEnd   = y + '-' + padZ(m+1) + '-' + padZ(new Date(y,m+1,0).getDate());
    var monthEvts  = vis.filter(function(e) { return e.date >= monthStart && e.date <= monthEnd; });

    var rows = monthEvts.map(function(e) {
        return '<tr style="border-bottom:1px solid #e5e7eb;">'
            + '<td style="padding:6px;">' + e.date + '</td>'
            + '<td style="padding:6px;font-weight:bold;color:' + (e.color||'#6B82F6') + ';">● ' + (e.title.split(' - ')[0] || e.title).replace(/</g,'&lt;') + '</td>'
            + '<td style="padding:6px;">' + (e.start_time ? formatTime(e.start_time) + ' – ' + formatTime(e.end_time) : '—') + '</td>'
            + '<td style="padding:6px;">' + (e.category||'').replace(/</g,'&lt;') + '</td>'
            + '<td style="padding:6px;">' + (e.location||'').replace(/</g,'&lt;') + '</td>'
            + '</tr>';
    }).join('');

    var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Calendar Export - ' + monthYearEl.textContent + '</title>'
        + '<style>body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;} h1{color:#1e3a8a;} table{width:100%;border-collapse:collapse;font-size:0.9rem;} th{padding:8px;background:#6B82F6;color:white;text-align:left;} td{padding:6px;} tr:nth-child(even){background:#f9fafb;}</style>'
        + '</head><body>'
        + '<h1>📅 ' + monthYearEl.textContent + '</h1>'
        + '<p style="color:#6b7280;">Generated: ' + new Date().toLocaleString() + ' | ' + monthEvts.length + ' events</p>'
        + '<table><thead><tr><th>Date</th><th>Event</th><th>Time</th><th>Category</th><th>Location</th></tr></thead>'
        + '<tbody>' + rows + '</tbody></table></body></html>';

    var blob = new Blob([html], { type: 'text/html' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'calendar-' + monthYearEl.textContent.replace(/[\s/\\:]/g, '-') + '.html';
    a.click();
    URL.revokeObjectURL(a.href);
    showNotifToast('HTML calendar exported.');
}

// ── #74 SMTP email reminder — test send ───────────────────────────────────────
function sendTestEmailReminder() {
    var email = document.getElementById('smtpTestEmail');
    if (!email || !email.value.trim()) { showNotifToast('Enter a test email address.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'send_test_email');
    body.set('to', email.value.trim());
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showNotifToast(data.ok ? 'Test email sent!' : 'Email failed: ' + (data.error || 'Check SMTP config in settings'));
        }).catch(function() { showNotifToast('Could not reach server.'); });
}

// ── #75 SMS reminder — test send ──────────────────────────────────────────────
function sendTestSmsReminder() {
    var phone = document.getElementById('twilioTestPhone');
    if (!phone || !phone.value.trim()) { showNotifToast('Enter a phone number.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'send_test_sms');
    body.set('to', phone.value.trim());
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showNotifToast(data.ok ? 'Test SMS sent!' : 'SMS failed: ' + (data.error || 'Check Twilio config'));
        }).catch(function() { showNotifToast('Could not reach server.'); });
}

// ── #76 CSV field mapping dialog ──────────────────────────────────────────────
var csvMappingHeaders = [];
var csvMappingRows = [];

function openCsvMappingDialog(headers, rows) {
    csvMappingHeaders = headers;
    csvMappingRows = rows;
    var modal = document.getElementById('csvMappingModal');
    var container = document.getElementById('csvMappingFields');
    if (!modal || !container) return;

    var fields = ['course_name','instructor_name','start_date','end_date','start_time','end_time','color','category','notes','event_url','priority','location','tags'];
    container.innerHTML = '<p style="font-size:0.82rem;color:#6b7280;margin-bottom:0.5rem;">Match CSV columns to calendar fields:</p>'
        + fields.map(function(f) {
            return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:0.85rem;">'
                + '<label style="width:130px;font-weight:600;">' + f.replace(/_/g,' ') + '</label>'
                + '<select data-field="' + f + '" style="flex:1;padding:4px 6px;border:1px solid #ccc;border-radius:4px;">'
                + '<option value="">-- skip --</option>'
                + headers.map(function(h, i) {
                    var sel = h.toLowerCase().replace(/[\s-]/g,'_').indexOf(f.replace(/_/g,'')) !== -1 ? ' selected' : '';
                    return '<option value="' + i + '"' + sel + '>' + h + '</option>';
                }).join('')
                + '</select></div>';
        }).join('');

    modal.style.display = 'flex';
}

function closeCsvMappingModal() {
    var m = document.getElementById('csvMappingModal'); if (m) m.style.display = 'none';
}

function applyCsvMapping() {
    var selects = document.querySelectorAll('#csvMappingFields select');
    var mapping = {};
    selects.forEach(function(sel) {
        if (sel.value !== '') mapping[sel.dataset.field] = parseInt(sel.value, 10);
    });

    if (!mapping.course_name || !mapping.start_date) {
        showNotifToast('At minimum, map Event Title and Start Date.');
        return;
    }

    var body = new URLSearchParams();
    body.set('action', 'import_csv_mapped');
    body.set('mapping', JSON.stringify(mapping));
    body.set('rows', JSON.stringify(csvMappingRows));
    showSpinner();
    closeCsvMappingModal();
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideSpinner();
            showNotifToast(data.ok ? 'Imported ' + (data.count || 0) + ' events!' : 'Import failed: ' + (data.error || ''));
            if (data.ok) setTimeout(function() { location.reload(); }, 1500);
        }).catch(function() { hideSpinner(); showNotifToast('Import failed.'); });
}

// ── #79 Birthday/anniversary auto-recurring ───────────────────────────────────
function detectBirthdayEvent(title) {
    return /birthday|anniversary|bday/i.test(title);
}

// ── #80 Generic webhook test trigger ─────────────────────────────────────────
function testWebhookTrigger() {
    var url = document.getElementById('webhookUrlInput');
    if (!url || !url.value.trim()) { showNotifToast('Set a webhook URL first.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'save_webhook');
    body.set('url', url.value.trim());
    body.set('test', '1');
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) { showNotifToast(data.ok ? 'Webhook saved & test ping queued.' : 'Failed.'); });
}

// ── #96 Custom reminder message parser ────────────────────────────────────────
function parseRemindersInput() {
    var input = document.getElementById('eventReminders');
    var preview = document.getElementById('remindersPreview');
    if (!input || !preview) return;
    try {
        var arr = JSON.parse(input.value || '[]');
        if (!Array.isArray(arr)) { preview.textContent = 'Invalid JSON array'; return; }
        preview.innerHTML = arr.map(function(r) {
            return '⏰ ' + (r.min || 15) + ' min before'
                + (r.message ? ' — "' + r.message.replace(/</g,'&lt;') + '"' : '');
        }).join('<br>') || '<span style="color:#9ca3af;">No reminders</span>';
    } catch(e) {
        preview.textContent = 'Parse error — use format: [{"min":15,"message":"Optional msg"}]';
        preview.style.color = '#b91c1c';
    }
}

var remindersInput2 = document.getElementById('eventReminders');
if (remindersInput2) remindersInput2.addEventListener('input', parseRemindersInput);

// ── #33 Split view ────────────────────────────────────────────────────────────
var splitViewDates = [new Date(), new Date()];

function renderSplitView(date) {
    var cal = document.getElementById('calendar');
    if (!cal) return;
    cal.innerHTML = '';
    var container = document.createElement('div');
    container.className = 'split-view';
    for (var p = 0; p < 2; p++) {
        var pane = document.createElement('div');
        pane.className = 'split-pane';
        pane.id = 'split-pane-' + p;
        var paneDate = splitViewDates[p] || new Date();
        var ds = toDateStr(paneDate);
        // Header with calendar selector and date nav
        var header = document.createElement('div');
        header.className = 'split-pane-header';
        var calSel = document.createElement('select');
        calSel.title = 'Filter calendar';
        calSel.innerHTML = '<option value="">All calendars</option>';
        if (typeof userCalendars !== 'undefined') {
            userCalendars.forEach(function(c) {
                calSel.innerHTML += '<option value="' + c.id + '">' + c.name + '</option>';
            });
        }
        (function(paneIdx, sel) {
            sel.onchange = function() { renderSplitPane(paneIdx, splitViewDates[paneIdx]); };
        })(p, calSel);
        calSel.id = 'split-cal-sel-' + p;
        var navLabel = document.createElement('span');
        navLabel.className = 'split-nav';
        navLabel.id = 'split-nav-label-' + p;
        navLabel.textContent = paneDate.toLocaleDateString(undefined, {weekday:'short', month:'short', day:'numeric', year:'numeric'});
        var prevBtn = document.createElement('button');
        prevBtn.textContent = '\u2039';
        prevBtn.className = 'nav-btn';
        prevBtn.style.cssText = 'padding:2px 8px;font-size:1rem;';
        (function(paneIdx) {
            prevBtn.onclick = function() { splitViewDates[paneIdx].setDate(splitViewDates[paneIdx].getDate() - 1); renderSplitPane(paneIdx, splitViewDates[paneIdx]); };
        })(p);
        var nextBtn = document.createElement('button');
        nextBtn.textContent = '\u203a';
        nextBtn.className = 'nav-btn';
        nextBtn.style.cssText = 'padding:2px 8px;font-size:1rem;';
        (function(paneIdx) {
            nextBtn.onclick = function() { splitViewDates[paneIdx].setDate(splitViewDates[paneIdx].getDate() + 1); renderSplitPane(paneIdx, splitViewDates[paneIdx]); };
        })(p);
        header.appendChild(prevBtn);
        header.appendChild(navLabel);
        header.appendChild(nextBtn);
        header.appendChild(calSel);
        pane.appendChild(header);
        var grid = document.createElement('div');
        grid.id = 'split-grid-' + p;
        pane.appendChild(grid);
        container.appendChild(pane);
    }
    cal.appendChild(container);
    renderSplitPane(0, splitViewDates[0]);
    renderSplitPane(1, splitViewDates[1]);
}

function renderSplitPane(paneIdx, date) {
    var grid = document.getElementById('split-grid-' + paneIdx);
    if (!grid) return;
    splitViewDates[paneIdx] = date;
    var label = document.getElementById('split-nav-label-' + paneIdx);
    if (label) label.textContent = date.toLocaleDateString(undefined, {weekday:'short', month:'short', day:'numeric', year:'numeric'});
    var ds = toDateStr(date);
    var sel = document.getElementById('split-cal-sel-' + paneIdx);
    var filtCalId = sel ? sel.value : '';
    var dayEvents = visibleEvents().filter(function(e) {
        if (filtCalId && String(e.calendar_id) !== String(filtCalId)) return false;
        return e.start === ds || (e.start <= ds && e.end >= ds);
    });
    grid.innerHTML = '';
    if (dayEvents.length === 0) {
        grid.innerHTML = '<div style="color:#9ca3af;font-size:0.83rem;padding:1rem;">No events this day.</div>';
        return;
    }
    dayEvents.forEach(function(ev) {
        var chip = document.createElement('div');
        chip.className = 'event';
        chip.style.cssText = 'background:' + (ev.color || '#6B82F6') + ';color:white;padding:4px 8px;border-radius:5px;margin-bottom:4px;font-size:0.82rem;cursor:pointer;';
        chip.textContent = (ev.start_time ? ev.start_time.slice(0,5) + ' ' : '') + ev.title;
        chip.onclick = function() { openModalForEdit(ev.id); };
        grid.appendChild(chip);
    });
}

// renderCalendar: dispatch to the correct view renderer
function renderCalendar(date) {
    if (currentView === 'split')     { renderSplitView(date);   return; }
    if (currentView === 'weekly')    { renderWeekly(date);      return; }
    if (currentView === 'biweekly')  { renderBiweekly(date);    return; }
    if (currentView === 'daily')     { renderDaily(date);       return; }
    if (currentView === 'agenda')    { renderAgenda(date);      return; }
    if (currentView === 'list')      { renderList(date);        return; }
    if (currentView === 'year')      { renderYear(date);        return; }
    if (currentView === 'quarter')   { renderQuarter(date);     return; }
    if (currentView === 'timeline')  { renderTimeline(date);    return; }
    if (currentView === 'heatmap')   { renderHeatmap(date);     return; }
    renderMonthly(date);
}

// switchView: transition to a new view
function switchView(view) {
    // Apply exit animation
    calendarEl.classList.add('view-exit');
    setTimeout(function() {
        calendarEl.classList.remove('view-exit');
        calendarEl.classList.add('view-enter');
        setTimeout(function() { calendarEl.classList.remove('view-enter'); }, 300);
    }, 150);

    currentView = view;
    localStorage.setItem('lastView', view);

    document.querySelectorAll('.view-btn').forEach(function(b) { b.classList.remove('active'); });
    var btn = document.getElementById('view-' + view);
    if (btn) btn.classList.add('active');

    var listRange = document.getElementById('listRangeDays');
    if (listRange) listRange.style.display = (view === 'list') ? 'inline-block' : 'none';

    renderCalendar(currentDate);
}

// ── #64 Activity Feed ─────────────────────────────────────────────────────────
var activityPanelOpen = false;

function toggleActivityPanel() {
    var panel = document.getElementById('activityPanel');
    if (!panel) return;
    activityPanelOpen = !activityPanelOpen;
    panel.classList.toggle('open', activityPanelOpen);
    if (activityPanelOpen) loadActivityFeed();
}

function loadActivityFeed() {
    var list = document.getElementById('activityList');
    if (!list) return;
    list.innerHTML = '<li class="activity-empty">Loading...</li>';
    fetch('ajax.php?action=get_activity')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.activity || data.activity.length === 0) {
                list.innerHTML = '<li class="activity-empty">No recent activity.</li>';
                return;
            }
            list.innerHTML = '';
            data.activity.forEach(function(item) {
                var li = document.createElement('li');
                var msg = item.message || item.action || 'Activity';
                var timeAgo = '';
                if (item.created_at) {
                    var diff = (Date.now() - new Date(item.created_at).getTime()) / 1000;
                    if (diff < 60) timeAgo = 'just now';
                    else if (diff < 3600) timeAgo = Math.floor(diff/60) + 'm ago';
                    else if (diff < 86400) timeAgo = Math.floor(diff/3600) + 'h ago';
                    else timeAgo = Math.floor(diff/86400) + 'd ago';
                }
                li.innerHTML = msg.replace(/</g,'&lt;') + (timeAgo ? '<span class="activity-time">' + timeAgo + '</span>' : '');
                list.appendChild(li);
            });
        }).catch(function() {
            list.innerHTML = '<li class="activity-empty">Failed to load.</li>';
        });
}

// ── #49 Send Invite Emails ────────────────────────────────────────────────────
function sendInviteEmails(eventId) {
    if (!eventId) { showNotifToast('Save the event first.'); return; }
    var attendeesEl = document.getElementById('eventAttendees');
    var emails = attendeesEl ? attendeesEl.value.trim() : '';
    if (!emails) { showNotifToast('No attendees to invite.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'send_invite_email');
    body.set('event_id', eventId);
    body.set('attendee_emails', emails);
    showSpinner();
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideSpinner();
            showNotifToast(data.ok ? 'Invites sent to ' + (data.sent || 0) + ' attendee(s).' : 'Failed: ' + (data.error || 'Unknown error'));
        }).catch(function() { hideSpinner(); showNotifToast('Failed to send invites.'); });
}

// ── #61 Sub-events ────────────────────────────────────────────────────────────
function loadSubEvents(parentId) {
    var container = document.getElementById('subEventsContent');
    if (!container || !parentId) return;
    container.innerHTML = '<div style="color:#9ca3af;font-size:0.8rem;">Loading...</div>';
    fetch('ajax.php?action=get_sub_events&parent_id=' + encodeURIComponent(parentId))
        .then(function(r) { return r.json(); })
        .then(function(data) { renderSubEvents(data.sub_events || [], container); })
        .catch(function() { container.innerHTML = '<div style="color:#b91c1c;font-size:0.8rem;">Failed to load.</div>'; });
}

function renderSubEvents(subEvents, container) {
    if (!container) return;
    if (!subEvents || subEvents.length === 0) {
        container.innerHTML = '<div style="color:#9ca3af;font-size:0.82rem;">No sub-events yet.</div>';
        return;
    }
    container.innerHTML = '';
    subEvents.forEach(function(ev) {
        var row = document.createElement('div');
        row.style.cssText = 'padding:4px 6px;border:1px solid #e5e7eb;border-radius:4px;margin-bottom:4px;font-size:0.82rem;display:flex;align-items:center;gap:6px;';
        row.innerHTML = '<span style="flex:1;">' + (ev.course_name || ev.title || 'Untitled').replace(/</g,'&lt;') + '</span>'
            + '<span style="color:#6b7280;font-size:0.75rem;">' + (ev.start_date || '') + '</span>';
        container.appendChild(row);
    });
}

function addSubEvent() {
    var parentId = document.getElementById('eventID') ? document.getElementById('eventID').value : '';
    var title = document.getElementById('subEventTitle') ? document.getElementById('subEventTitle').value.trim() : '';
    var date  = document.getElementById('subEventDate')  ? document.getElementById('subEventDate').value  : '';
    if (!parentId) { showNotifToast('Save the parent event first.'); return; }
    if (!title)    { showNotifToast('Enter a sub-event title.'); return; }
    if (!date)     { showNotifToast('Enter a date for the sub-event.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'add_sub_event');
    body.set('parent_id', parentId);
    body.set('title', title);
    body.set('start_date', date);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                showNotifToast('Sub-event added!');
                document.getElementById('subEventTitle').value = '';
                document.getElementById('subEventDate').value = '';
                loadSubEvents(parentId);
            } else {
                showNotifToast('Failed: ' + (data.error || 'Unknown error'));
            }
        }).catch(function() { showNotifToast('Failed to add sub-event.'); });
}

// switchModalTab: base + load sub-events/permissions on tab switch
function switchModalTab(tab) {
    // Base implementation: show/hide tab panes
    document.querySelectorAll('.modal-tab-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.querySelectorAll('.modal-tab-pane').forEach(function(pane) {
        pane.style.display = (pane.dataset.tab === tab) ? 'block' : 'none';
    });
    // Load dynamic content for tabs
    var eventId = document.getElementById('eventID') ? document.getElementById('eventID').value : '';
    if (tab === 'comments' && eventId) loadComments(eventId);
    if (tab === 'history'  && eventId) loadEventHistory(eventId);
    if (tab === 'subevents' && eventId) loadSubEvents(eventId);
    if (tab === 'sharing'   && eventId) loadEventPermissions(eventId);
}

// ── #62 Event Permissions ─────────────────────────────────────────────────────
function loadEventPermissions(eventId) {
    var container = document.getElementById('eventPermissionsContent');
    if (!container || !eventId) return;
    container.innerHTML = '<div style="color:#9ca3af;font-size:0.8rem;">Loading...</div>';
    fetch('ajax.php?action=get_event_permissions&event_id=' + encodeURIComponent(eventId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.permissions || data.permissions.length === 0) {
                container.innerHTML = '<div style="color:#9ca3af;font-size:0.82rem;">No permissions granted yet.</div>';
                return;
            }
            container.innerHTML = '';
            data.permissions.forEach(function(perm) {
                var row = document.createElement('div');
                row.style.cssText = 'padding:4px 6px;border:1px solid #e5e7eb;border-radius:4px;margin-bottom:4px;font-size:0.82rem;display:flex;align-items:center;gap:6px;';
                row.innerHTML = '<span style="flex:1;">' + (perm.username || '?').replace(/</g,'&lt;') + '</span>'
                    + '<span style="background:#e0e7ff;color:#3730a3;padding:1px 6px;border-radius:10px;font-size:0.75rem;">' + (perm.permission || '') + '</span>'
                    + '<button onclick="revokePermission(' + perm.id + ')" style="background:none;border:none;cursor:pointer;color:#b91c1c;font-size:0.85rem;">&#10005;</button>';
                container.appendChild(row);
            });
        }).catch(function() { container.innerHTML = '<div style="color:#b91c1c;font-size:0.8rem;">Failed to load.</div>'; });
}

function grantPermission(eventId, username, permission) {
    if (!eventId) { showNotifToast('Save the event first.'); return; }
    username = username || (document.getElementById('grantUsername') ? document.getElementById('grantUsername').value.trim() : '');
    permission = permission || (document.getElementById('grantPermission') ? document.getElementById('grantPermission').value : 'view');
    if (!username) { showNotifToast('Enter a username.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'grant_event_permission');
    body.set('event_id', eventId);
    body.set('username', username);
    body.set('permission', permission);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                showNotifToast('Permission granted!');
                var gu = document.getElementById('grantUsername'); if (gu) gu.value = '';
                loadEventPermissions(eventId);
            } else {
                showNotifToast('Failed: ' + (data.error || 'Unknown error'));
            }
        }).catch(function() { showNotifToast('Failed to grant permission.'); });
}

function revokePermission(permId) {
    var eventId = document.getElementById('eventID') ? document.getElementById('eventID').value : '';
    var body = new URLSearchParams();
    body.set('action', 'revoke_event_permission');
    body.set('perm_id', permId);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) { showNotifToast('Permission revoked.'); loadEventPermissions(eventId); }
            else showNotifToast('Failed: ' + (data.error || 'Unknown error'));
        }).catch(function() { showNotifToast('Failed to revoke.'); });
}

// ── #66 Calendar Shares ───────────────────────────────────────────────────────
var _currentShareCalId = null;

function showCalendarShareModal(calId, calName) {
    _currentShareCalId = calId;
    var modal = document.getElementById('calendarShareModal');
    var title = document.getElementById('calShareModalTitle');
    if (modal) modal.style.display = 'flex';
    if (title) title.textContent = calName || '';
    loadCalendarShares(calId);
}

function closeCalendarShareModal() {
    var modal = document.getElementById('calendarShareModal');
    if (modal) modal.style.display = 'none';
    _currentShareCalId = null;
}

function loadCalendarShares(calId) {
    var list = document.getElementById('calShareList');
    if (!list) return;
    list.innerHTML = '<div style="color:#9ca3af;font-size:0.82rem;">Loading...</div>';
    fetch('ajax.php?action=get_calendar_shares&cal_id=' + encodeURIComponent(calId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.shares || data.shares.length === 0) {
                list.innerHTML = '<div style="color:#9ca3af;font-size:0.82rem;padding:0.3rem;">Not shared with anyone yet.</div>';
                return;
            }
            list.innerHTML = '';
            data.shares.forEach(function(share) {
                var row = document.createElement('div');
                row.style.cssText = 'padding:4px 6px;border-bottom:1px solid #f3f4f6;font-size:0.82rem;display:flex;align-items:center;gap:6px;';
                row.innerHTML = '<span style="flex:1;">' + (share.username || '?').replace(/</g,'&lt;') + '</span>'
                    + '<span style="background:#e0e7ff;color:#3730a3;padding:1px 6px;border-radius:10px;font-size:0.75rem;">' + (share.permission || '') + '</span>'
                    + '<button onclick="unshareCalendar(' + share.id + ')" style="background:none;border:none;cursor:pointer;color:#b91c1c;font-size:0.85rem;">&#10005;</button>';
                list.appendChild(row);
            });
        }).catch(function() { list.innerHTML = '<div style="color:#b91c1c;font-size:0.82rem;">Failed to load.</div>'; });
}

function submitCalendarShare() {
    if (!_currentShareCalId) return;
    var username = document.getElementById('calShareUsername') ? document.getElementById('calShareUsername').value.trim() : '';
    var permission = document.getElementById('calSharePermission') ? document.getElementById('calSharePermission').value : 'view';
    if (!username) { showNotifToast('Enter a username.'); return; }
    var body = new URLSearchParams();
    body.set('action', 'share_calendar');
    body.set('cal_id', _currentShareCalId);
    body.set('username', username);
    body.set('permission', permission);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                showNotifToast('Calendar shared!');
                var el = document.getElementById('calShareUsername'); if (el) el.value = '';
                loadCalendarShares(_currentShareCalId);
            } else {
                showNotifToast('Failed: ' + (data.error || 'Unknown error'));
            }
        }).catch(function() { showNotifToast('Failed to share.'); });
}

function unshareCalendar(shareId) {
    var body = new URLSearchParams();
    body.set('action', 'unshare_calendar');
    body.set('share_id', shareId);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) { showNotifToast('Removed share.'); loadCalendarShares(_currentShareCalId); }
            else showNotifToast('Failed: ' + (data.error || 'Unknown error'));
        }).catch(function() { showNotifToast('Failed to unshare.'); });
}

// ── #55 Calendar Groups ───────────────────────────────────────────────────────
function setCalendarGroup(calId, groupName) {
    var body = new URLSearchParams();
    body.set('action', 'set_calendar_group');
    body.set('cal_id', calId);
    body.set('group_name', groupName || '');
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) showNotifToast('Calendar group updated.');
            else showNotifToast('Failed: ' + (data.error || ''));
        });
}

function renameCalendarGroup(el) {
    var groupEl = el.closest('.calendar-group');
    if (!groupEl) return;
    var newName = el.textContent.trim();
    var oldName = groupEl.dataset.group;
    if (!newName || newName === oldName) return;
    // Rename all calendars in this group to the new group name
    var items = groupEl.querySelectorAll('.cal-item');
    items.forEach(function(item) {
        var calId = item.dataset.calId;
        if (calId) setCalendarGroup(calId, newName);
    });
    groupEl.dataset.group = newName;
}

// handleEventSelection: populate modal fields from event data + sub-events/permissions + version (#59)
function handleEventSelection(eventJSON) {
    if (!eventJSON) return;
    var event;
    try { event = (typeof eventJSON === 'string') ? JSON.parse(eventJSON) : eventJSON; } catch(e) { return; }

    document.getElementById('formAction').value = 'edit';
    var idEl = document.getElementById('eventID'); if (idEl) idEl.value = event.id || '';
    var sdEl = document.getElementById('startDate'); if (sdEl) sdEl.value = event.start || event.date || '';
    var edEl = document.getElementById('endDate'); if (edEl) edEl.value = event.end || event.date || '';
    var stEl = document.getElementById('startTime'); if (stEl) stEl.value = event.start_time || '';
    var etEl = document.getElementById('endTime'); if (etEl) etEl.value = event.end_time || '';
    var cnEl = document.getElementById('courseName'); if (cnEl) cnEl.value = event.course_name || (event.title ? event.title.split(' - ')[0] : '') || '';
    var inEl = document.getElementById('instructorName'); if (inEl) inEl.value = event.instructor_name || (event.title ? event.title.split(' - ')[1] || '' : '') || '';
    var colEl = document.getElementById('eventColor'); if (colEl) colEl.value = event.color || '#6B82F6';
    var catEl = document.getElementById('eventCategory'); if (catEl) catEl.value = event.category || '';
    var priEl = document.getElementById('eventPriority'); if (priEl) priEl.value = String(event.priority || 1);
    var notEl = document.getElementById('eventNotes'); if (notEl) notEl.value = event.notes || '';
    var recEl = document.getElementById('recurrenceSelect'); if (recEl) recEl.value = event.recurrence || 'none';
    var recEnd = document.getElementById('recurrenceEndDate'); if (recEnd) recEnd.value = event.recurrence_end || '';
    toggleRecurrenceEnd(event.recurrence || 'none');

    // Extra fields
    var locEl = document.getElementById('eventLocation'); if (locEl) locEl.value = event.location || '';
    var tagEl = document.getElementById('eventTags'); if (tagEl) tagEl.value = event.tags || '';
    var attEl = document.getElementById('eventAttendees'); if (attEl) attEl.value = event.attendees || '';
    var urlEl = document.getElementById('eventUrl'); if (urlEl) urlEl.value = event.event_url || '';
    var zoomEl = document.getElementById('eventZoomUrl'); if (zoomEl) zoomEl.value = event.zoom_url || '';
    var remEl = document.getElementById('eventReminders'); if (remEl) remEl.value = event.reminders || '';
    var dlEl = document.getElementById('eventDeadline'); if (dlEl) dlEl.value = event.deadline || '';
    var capEl = document.getElementById('eventCapacity'); if (capEl) capEl.value = event.capacity || '';
    var asEl = document.getElementById('eventActualStart'); if (asEl) asEl.value = event.actual_start || '';
    var aeEl = document.getElementById('eventActualEnd'); if (aeEl) aeEl.value = event.actual_end || '';
    var subEl = document.getElementById('eventSubtasks'); if (subEl) subEl.value = event.subtasks || '';
    var relEl = document.getElementById('eventRelatedIds'); if (relEl) relEl.value = event.related_ids || '';
    var attEl2 = document.getElementById('eventAttachment'); if (attEl2) attEl2.value = event.attachment || '';
    var calEl = document.getElementById('eventCalendar'); if (calEl && event.calendar_id) calEl.value = event.calendar_id;

    // Version for optimistic locking (#59)
    var vEl = document.getElementById('eventVersion');
    if (vEl) vEl.value = event.version || 0;

    // Delete form event ID
    var delIdEl = document.getElementById('deleteEventID'); if (delIdEl) delIdEl.value = event.id || '';

    updateLocationLink();
    updateDeadlineCountdown();
    if (typeof updateZoomBtn === 'function') updateZoomBtn();
    if (typeof renderRsvpDisplay === 'function') renderRsvpDisplay(event.attendees || '');
    if (typeof updateRelatedEventsDisplay === 'function') updateRelatedEventsDisplay();
    if (typeof renderSubtasksChecklist === 'function') renderSubtasksChecklist();
    if (typeof parseRemindersInput === 'function') parseRemindersInput();
    checkConflicts();
}

// ── #45 Fix saveFilterPreset to use ajax.php ──────────────────────────────────
function saveFilterPreset() {
    var name = prompt('Preset name:');
    if (!name) return;
    var filters = JSON.stringify(activeFilters);
    var body = new URLSearchParams();
    body.set('action', 'save_filter_preset');
    body.set('preset_name', name);
    body.set('preset_filters', filters);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showNotifToast(data.ok ? 'Filter preset saved!' : 'Failed: ' + (data.error || ''));
        }).catch(function() { showNotifToast('Failed to save preset.'); });
}

// ── toggleNotifCenter: toggle panel + load server-side notifications (#67, #93) ─
function toggleNotifCenter() {
    var panel = document.getElementById('notifCenter');
    if (!panel) return;
    var isOpen = panel.style.display === 'block';
    panel.style.display = isOpen ? 'none' : 'block';
    if (!isOpen) {
        renderNotifCenter();
        // Load server-side notifications (#67)
        fetch('ajax.php?action=get_notifications')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok || !data.notifications || data.notifications.length === 0) return;
                var list = document.getElementById('notifList');
                if (!list) return;
                var serverHtml = data.notifications.map(function(n) {
                    return '<div style="padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:0.82rem;' + (!n.is_read ? 'font-weight:bold;' : '') + '">'
                        + (n.message || '').replace(/</g,'&lt;')
                        + '<button onclick="markNotifRead(' + n.id + ',this)" style="margin-left:6px;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:0.75rem;">&#10003; Read</button>'
                        + '</div>';
                }).join('');
                list.innerHTML = serverHtml + list.innerHTML;
            }).catch(function() {});
    }
}

function markNotifRead(notifId, btn) {
    var body = new URLSearchParams();
    body.set('action', 'mark_notification_read');
    body.set('notif_id', notifId);
    fetch('ajax.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && btn) {
                var row = btn.closest('div');
                if (row) row.style.fontWeight = 'normal';
                btn.remove();
            }
        });
}

// ── Escape key closes new modals ──────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeShareCalendarModal();
        closeCsvMappingModal();
        closeCalendarShareModal();
    }
});
