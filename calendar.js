const calendarEl = document.getElementById('calendar');
const monthYearEl = document.getElementById('monthYear');
const modalEl = document.getElementById('eventModal');

let currentDate = new Date();

function renderCalendar(date) {
    calendarEl.innerHTML = '';

    const year = date.getFullYear();
    const month = date.getMonth();
    const today = new Date();
    const totalDays = new Date(year, month + 1, 0).getDate();
    const firstDayOfMonth = new Date(year, month, 1).getDay();

    monthYearEl.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    // Day name headers
    const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    weekDays.forEach(day => {
        const dayEl = document.createElement('div');
        dayEl.className = 'day-name';
        dayEl.textContent = day;
        calendarEl.appendChild(dayEl);
    });

    // Empty cells before the first day
    for (let i = 0; i < firstDayOfMonth; i++) {
        calendarEl.appendChild(document.createElement('div'));
    }

    // Day cells
    for (let day = 1; day <= totalDays; day++) {
        const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

        const cell = document.createElement('div');
        cell.className = 'day';

        if (
            day === today.getDate() &&
            month === today.getMonth() &&
            year === today.getFullYear()
        ) {
            cell.classList.add('today');
        }

        const dateEl = document.createElement('div');
        dateEl.className = 'date-number';
        dateEl.textContent = day;
        cell.appendChild(dateEl);

        // Events for this day
        const eventsToday = events.filter(e => e.date === dateString);

        eventsToday.forEach(event => {
            const ev = document.createElement('div');
            ev.className = 'event';

            const parts = event.title.split(' - ');
            const courseEl = document.createElement('div');
            courseEl.className = 'course';
            courseEl.textContent = parts[0] || '';

            const instructorEl = document.createElement('div');
            instructorEl.className = 'instructor';
            instructorEl.textContent = '\uD83D\uDC68\u200D\uD83C\uDFEB ' + (parts[1] || '');

            const timeEl = document.createElement('div');
            timeEl.className = 'time';
            timeEl.textContent = '\uD83D\uDD50 ' + event.start_time + ' - ' + event.end_time;

            ev.appendChild(courseEl);
            ev.appendChild(instructorEl);
            ev.appendChild(timeEl);
            cell.appendChild(ev);
        });

        // Overlay buttons (Add / Edit)
        const overlay = document.createElement('div');
        overlay.className = 'day-overlay';

        const addBtn = document.createElement('button');
        addBtn.className = 'overlay-btn';
        addBtn.textContent = '+ Add';
        addBtn.onclick = function (e) {
            e.stopPropagation();
            openModalForAdd(dateString);
        };
        overlay.appendChild(addBtn);

        if (eventsToday.length > 0) {
            const editBtn = document.createElement('button');
            editBtn.className = 'overlay-btn';
            editBtn.textContent = 'Edit';
            editBtn.onclick = function (e) {
                e.stopPropagation();
                openModalForEdit(eventsToday);
            };
            overlay.appendChild(editBtn);
        }

        cell.appendChild(overlay);
        calendarEl.appendChild(cell);
    }
}

function openModalForAdd(dateString) {
    document.getElementById('formAction').value = 'add';
    document.getElementById('eventID').value = '';
    document.getElementById('deleteEventID').value = '';
    document.getElementById('courseName').value = '';
    document.getElementById('instructorName').value = '';
    document.getElementById('startDate').value = dateString;
    document.getElementById('endDate').value = dateString;
    document.getElementById('startTime').value = '09:00';
    document.getElementById('endTime').value = '10:00';

    document.getElementById('eventSelectorWrapper').style.display = 'none';

    modalEl.style.display = 'flex';
}

function openModalForEdit(eventsOnDate) {
    document.getElementById('formAction').value = 'edit';

    const selector = document.getElementById('eventSelector');
    const wrapper = document.getElementById('eventSelectorWrapper');

    selector.innerHTML = '<option disabled selected>Choose event...</option>';

    eventsOnDate.forEach(function (e) {
        const option = document.createElement('option');
        option.value = JSON.stringify(e);
        option.textContent = e.title.split(' - ')[0] + ' (' + e.start + ' \u2192 ' + e.end + ')';
        selector.appendChild(option);
    });

    if (eventsOnDate.length > 1) {
        wrapper.style.display = 'block';
    } else {
        wrapper.style.display = 'none';
    }

    // Pre-fill with first event
    handleEventSelection(JSON.stringify(eventsOnDate[0]));

    modalEl.style.display = 'flex';
}

function handleEventSelection(eventJSON) {
    const event = JSON.parse(eventJSON);

    document.getElementById('eventID').value = event.id;
    document.getElementById('deleteEventID').value = event.id;

    const parts = event.title.split(' - ');
    document.getElementById('courseName').value = parts[0] ? parts[0].trim() : '';
    document.getElementById('instructorName').value = parts[1] ? parts[1].trim() : '';
    document.getElementById('startDate').value = event.start;
    document.getElementById('endDate').value = event.end;
    document.getElementById('startTime').value = event.start_time;
    document.getElementById('endTime').value = event.end_time;
}

function closeModal() {
    modalEl.style.display = 'none';
}

function changeMonth(offset) {
    currentDate.setMonth(currentDate.getMonth() + offset);
    renderCalendar(currentDate);
}

function updateClock() {
    const now = new Date();
    const clock = document.getElementById('clock');
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    clock.textContent = h + ':' + m + ':' + s;
}

// Initialize
renderCalendar(currentDate);
updateClock();
setInterval(updateClock, 1000);
