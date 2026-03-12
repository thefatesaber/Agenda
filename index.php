<?php include 'calendar.php'; ?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar Project</title>
    <meta name="description" content="My calendar project">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <!-- Marked.js for markdown rendering (#23) -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
</head>
<body>

    <header>
        <h1>&#128197; Course Calendar<br>My Calendar Project</h1>
        <button id="darkToggle" class="dark-toggle-btn" onclick="toggleDark()">Dark Mode</button>
        <!-- Bell icon notification center (#93) -->
        <button id="notifBell" class="dark-toggle-btn" style="right:10rem" onclick="toggleNotifCenter()">&#128276; <span id="notifCount" class="notif-badge" style="display:none">0</span></button>
        <button id="notifToggle" class="dark-toggle-btn" style="right:14rem" onclick="toggleNotifications()">&#128276; Enable</button>
        <!-- Activity feed button (#64) -->
        <button id="activityBtn" class="dark-toggle-btn" style="right:18rem" onclick="toggleActivityPanel()">&#128196; Activity</button>
        <?php if (REQUIRE_AUTH && !empty($_SESSION['username'])): ?>
        <div class="user-info">
            <a href="profile.php" style="color:rgba(255,255,255,0.9);text-decoration:none;">&#128100; <?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username']) ?></a>
            &mdash; <a href="logout.php" style="color:rgba(255,255,255,0.85);text-decoration:underline;">Sign out</a>
        </div>
        <?php endif; ?>
        <!-- In-app notification center panel (#93) -->
        <div id="notifCenter" style="display:none;position:absolute;top:60px;right:10rem;width:320px;max-height:400px;overflow-y:auto;background:white;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,0.2);z-index:9999;padding:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <strong>Notifications</strong>
                <button onclick="clearNotifications()" style="font-size:0.75rem;background:none;border:none;color:#6b7280;cursor:pointer;">Clear all</button>
            </div>
            <div id="notifList" style="font-size:0.85rem;color:#374151;"></div>
        </div>
    </header>

    <!-- Activity feed slide-in panel (#64) -->
    <div id="activityPanel" class="activity-panel">
        <div class="activity-panel-header">
            <strong>&#128196; Activity Feed</strong>
            <button onclick="toggleActivityPanel()" class="activity-close-btn">&#10005;</button>
        </div>
        <ul id="activityList" class="activity-list">
            <li class="activity-empty">Loading...</li>
        </ul>
    </div>

    <!-- Clock -->
    <div class="clock-container">
        <div id="clock"></div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <input type="text" class="search-bar" id="searchBar" placeholder="&#128269; Search events..." oninput="filterAndRender(this.value)" autocomplete="off">
        <!-- Recent searches dropdown (#44) -->
        <div id="recentSearchesDropdown" class="recent-searches-dropdown" style="display:none;"></div>
        <button class="export-btn" onclick="goToToday()">Today</button>
        <!-- Jump to date (#3) -->
        <input type="date" id="jumpToDateInput" class="export-btn" style="padding:5px 8px;cursor:pointer;" title="Jump to date" onchange="jumpToDate(this.value)">
        <button class="export-btn" id="collapseHoursBtn" onclick="toggleCollapseHours()">Collapse Hours</button>
        <button class="export-btn" id="bulkSelectBtn" onclick="toggleBulkSelect()">&#9745; Select</button>
        <!-- Sidebar toggle (#17) -->
        <button class="export-btn" id="sidebarToggleBtn" onclick="toggleSidebar()" title="Toggle sidebar">&#9776; Sidebar</button>
        <a class="export-btn" href="index.php?action=export_csv">Export CSV</a>
        <a class="export-btn" href="index.php?action=export_ical">Export iCal</a>
        <!-- PDF Export (#77) -->
        <button class="export-btn" onclick="exportPDF()" title="Export to PDF">&#128196; PDF</button>
        <!-- HTML export (#68) -->
        <button class="export-btn" onclick="exportStaticHtml()" title="Export static HTML page">&#127760; HTML Export</button>
        <!-- Share calendar URL + embed (#57, #58) -->
        <button class="export-btn" onclick="showShareCalendarModal()" title="Share calendar">&#128279; Share</button>
        <!-- ICS URL import (#56, #71, #72) -->
        <button class="export-btn" onclick="document.getElementById('icsImportPanel').style.display=document.getElementById('icsImportPanel').style.display==='none'?'block':'none'" title="Import from URL">&#128197; ICS URL</button>
        <form method="POST" enctype="multipart/form-data" style="display:inline">
            <input type="hidden" name="action" value="import_csv">
            <label class="export-btn" style="cursor:pointer;">
                Import CSV/iCal
                <input type="file" name="csv_file" accept=".csv,.ics" style="display:none" onchange="showSpinner(); this.form.submit();">
            </label>
        </form>
        <button class="export-btn" onclick="window.print()">&#128424; Print</button>
        <button class="export-btn" onclick="toggleSettingsPanel()">&#9881;&#65039; Settings</button>
        <!-- Shortcut reference (#4) -->
        <button class="export-btn" onclick="showShortcutsModal()" title="Keyboard shortcuts (?)">&#9000; Shortcuts</button>
        <select id="listRangeDays" style="display:none;padding:5px 8px;font-size:0.82rem;border:1px solid var(--primary);border-radius:6px;" onchange="renderCalendar(currentDate)">
            <option value="7">7 days</option>
            <option value="14">14 days</option>
            <option value="30" selected>30 days</option>
            <option value="90">90 days</option>
        </select>
    </div>

    <!-- Bulk actions bar -->
    <div id="bulkActionsBar" style="display:none;max-width:1100px;margin:0.5rem auto;padding:0.6rem 1rem;background:white;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.1);align-items:center;gap:10px;flex-wrap:wrap;">
        <span id="bulkCount" style="font-weight:bold;font-size:0.9rem;">0 selected</span>
        <label style="font-size:0.85rem;margin:0;">Color: <input type="color" id="bulkColor" value="#6B82F6" style="height:28px;padding:0 2px;vertical-align:middle;"></label>
        <label style="font-size:0.85rem;margin:0;">Category: <input type="text" id="bulkCategory" placeholder="Category..." style="padding:4px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;width:120px;"></label>
        <label style="font-size:0.85rem;margin:0;">Shift days: <input type="number" id="bulkShiftDays" value="0" style="padding:4px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;width:70px;"></label>
        <button class="export-btn" onclick="applyBulkEdit()">Apply</button>
        <button class="export-btn" onclick="applyBulkReschedule()">&#8594; Reschedule</button>
        <button class="export-btn" style="color:#b91c1c;border-color:#b91c1c;" onclick="applyBulkDelete()">&#128465; Delete Selected</button>
        <button class="export-btn" onclick="clearBulkSelect()">Cancel</button>
    </div>

    <!-- ICS URL import panel (#56, #71, #72) -->
    <div id="icsImportPanel" style="display:none;max-width:1100px;margin:0.4rem auto;padding:0.6rem 1rem;background:white;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.1);">
        <strong style="font-size:0.9rem;">&#128197; Import Calendar from URL (Google/Outlook .ics feed):</strong>
        <div class="ics-url-form" style="margin-top:6px;">
            <input type="url" id="icsUrlInput" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics" style="flex:1;padding:6px 10px;font-size:0.85rem;border:1px solid #ccc;border-radius:4px;">
            <button onclick="importIcsFromUrl()" class="export-btn" style="white-space:nowrap;">Import</button>
        </div>
        <div style="font-size:0.75rem;color:#9ca3af;margin-top:4px;">Supports public Google Calendar, Outlook, iCal feeds. Recurring events and VEVENTs supported.</div>
    </div>

    <!-- Main layout with sidebar -->
    <div id="mainLayout" class="main-layout">

        <!-- Mini sidebar (#15, #17) -->
        <div id="sidebar" class="sidebar" style="display:none;">
            <!-- Mini calendar for navigation (#15) -->
            <div class="sidebar-section">
                <div class="sidebar-title">Quick Navigation</div>
                <div id="miniCal"></div>
            </div>

            <!-- Multiple calendars panel (#51-60, #55 groups) -->
            <div class="sidebar-section">
                <div class="sidebar-title" style="display:flex;justify-content:space-between;align-items:center;">
                    Calendars
                    <button onclick="showCreateCalendarModal()" style="background:none;border:none;cursor:pointer;color:var(--primary);font-size:1.2rem;">+</button>
                </div>
                <div id="calendarList">
                    <?php
                    // Group calendars by group_name (#55)
                    $calendarGroups = [];
                    foreach ($userCalendars as $cal) {
                        $grp = !empty($cal['group_name']) ? $cal['group_name'] : '__ungrouped__';
                        $calendarGroups[$grp][] = $cal;
                    }
                    ksort($calendarGroups);

                    foreach ($calendarGroups as $groupName => $groupCals):
                        if ($groupName !== '__ungrouped__'):
                    ?>
                    <div class="calendar-group" data-group="<?= htmlspecialchars($groupName) ?>">
                        <div class="calendar-group-header" onclick="this.closest('.calendar-group').classList.toggle('collapsed')">
                            <span class="calendar-group-toggle">&#9660;</span>
                            <span class="calendar-group-name" contenteditable="true" onblur="renameCalendarGroup(this)" title="Click to rename"><?= htmlspecialchars($groupName) ?></span>
                            <span class="calendar-group-badge"><?= count($groupCals) ?></span>
                        </div>
                        <div class="calendar-group-items">
                    <?php endif; ?>

                    <?php foreach ($groupCals as $cal): ?>
                    <div class="cal-item" data-cal-id="<?= $cal['id'] ?>">
                        <input type="checkbox" class="cal-visibility-toggle" data-cal-id="<?= $cal['id'] ?>" checked onchange="toggleCalendarVisibility(<?= $cal['id'] ?>, this.checked)">
                        <span class="cal-color-dot" style="background:<?= htmlspecialchars($cal['color']) ?>"></span>
                        <span class="cal-name"><?= htmlspecialchars($cal['name']) ?></span>
                        <button class="cal-share-btn" onclick="showCalendarShareModal(<?= $cal['id'] ?>, '<?= htmlspecialchars(addslashes($cal['name'])) ?>')" title="Share calendar">&#128279;</button>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($groupName !== '__ungrouped__'): ?>
                        </div><!-- .calendar-group-items -->
                    </div><!-- .calendar-group -->
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if (empty($userCalendars)): ?>
                    <div style="font-size:0.8rem;color:#9ca3af;">No calendars yet. Click + to create.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter panel (#41) -->
            <div class="sidebar-section">
                <div class="sidebar-title">Filters</div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Category: <input type="text" id="filterCategory" class="filter-input" placeholder="Any..." oninput="applyFilterPanel()"></label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Priority:
                        <select id="filterPriority" class="filter-input" onchange="applyFilterPanel()">
                            <option value="">Any</option>
                            <option value="1">Low</option>
                            <option value="2">Medium</option>
                            <option value="3">High</option>
                        </select>
                    </label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Color: <input type="color" id="filterColor" class="filter-input" onchange="applyFilterPanel()" style="height:24px;width:50px;padding:0;vertical-align:middle;"></label>
                    <button onclick="clearFilterColor()" style="font-size:0.75rem;background:none;border:none;cursor:pointer;color:#6b7280;">✕ clear</button>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Tag: <input type="text" id="filterTag" class="filter-input" placeholder="Any..." oninput="applyFilterPanel()"></label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Attendee: <input type="text" id="filterAttendee" class="filter-input" placeholder="email..." oninput="applyFilterPanel()"></label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Location: <input type="text" id="filterLocation" class="filter-input" placeholder="Any..." oninput="applyFilterPanel()"></label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>From: <input type="date" id="filterDateFrom" class="filter-input" onchange="applyFilterPanel()"></label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>To: <input type="date" id="filterDateTo" class="filter-input" onchange="applyFilterPanel()"></label>
                </div>
                <div style="font-size:0.82rem;margin-bottom:0.4rem;">
                    <label>Logic:
                        <select id="filterLogic" class="filter-input" onchange="applyFilterPanel()">
                            <option value="AND">AND</option>
                            <option value="OR">OR</option>
                        </select>
                    </label>
                </div>
                <button onclick="clearFilters()" class="export-btn" style="font-size:0.75rem;padding:4px 8px;">Clear Filters</button>
                <!-- Saved filter presets (#45) -->
                <div style="margin-top:0.5rem;">
                    <button onclick="saveFilterPreset()" class="export-btn" style="font-size:0.75rem;padding:4px 8px;">&#128190; Save Preset</button>
                    <select id="filterPresetSelect" class="filter-input" style="font-size:0.75rem;margin-top:4px;width:100%;" onchange="loadFilterPreset(this.value)">
                        <option value="">-- Load preset --</option>
                        <?php foreach ($filterPresets as $fp): ?>
                        <option value="<?= htmlspecialchars($fp['filters']) ?>"><?= htmlspecialchars($fp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Free-time finder (#81) -->
            <div class="sidebar-section">
                <div class="sidebar-title">Free Time Finder</div>
                <div style="font-size:0.82rem;">
                    <label>Date: <input type="date" id="freeTimeDateInput" class="filter-input" value="<?= date('Y-m-d') ?>"></label>
                    <button onclick="findFreeTime()" class="export-btn" style="font-size:0.75rem;padding:4px 8px;margin-top:4px;">Find Slots</button>
                    <div id="freeTimeResult" style="margin-top:0.4rem;font-size:0.8rem;color:#374151;"></div>
                </div>
            </div>

            <!-- Statistics (#83) -->
            <div class="sidebar-section">
                <div class="sidebar-title" style="cursor:pointer;" onclick="toggleStatsDashboard()">&#128200; Statistics &#9660;</div>
                <div id="statsDashboard" style="display:none;"></div>
            </div>

            <!-- Weekly digest (#95) -->
            <div class="sidebar-section" id="weeklyDigest">
                <div class="sidebar-title">&#128203; Next 7 Days</div>
                <div id="digestContent" style="font-size:0.82rem;"></div>
            </div>
        </div>

        <!-- Calendar area -->
        <div id="calendarArea" class="calendar-area">
            <div class="calendar">
                <div class="nav-btn-container">
                    <div class="nav-btn" onclick="changeMonth(-1)">&#8249;</div>
                    <h2 id="monthYear" style="margin: 0; cursor:pointer;" onclick="toggleMonthPicker()"></h2>
                    <button class="nav-btn" onclick="changeMonth(1)">&#8250;</button>
                </div>

                <!-- Month picker -->
                <div id="monthPicker" style="display:none;position:absolute;background:white;box-shadow:0 4px 16px rgba(0,0,0,0.15);border-radius:10px;padding:1rem;z-index:1000;min-width:240px;"></div>

                <div class="view-toggle">
                    <button class="view-btn active" id="view-monthly" onclick="switchView('monthly')">Monthly</button>
                    <button class="view-btn" id="view-weekly" onclick="switchView('weekly')">Weekly</button>
                    <button class="view-btn" id="view-biweekly" onclick="switchView('biweekly')">2-Week</button>
                    <button class="view-btn" id="view-daily" onclick="switchView('daily')">Daily</button>
                    <button class="view-btn" id="view-agenda" onclick="switchView('agenda')">Agenda</button>
                    <button class="view-btn" id="view-list" onclick="switchView('list')">List</button>
                    <!-- New views (#9, #10, #11, #12) -->
                    <button class="view-btn" id="view-year" onclick="switchView('year')">Year</button>
                    <button class="view-btn" id="view-quarter" onclick="switchView('quarter')">Quarter</button>
                    <button class="view-btn" id="view-timeline" onclick="switchView('timeline')">Timeline</button>
                    <button class="view-btn" id="view-heatmap" onclick="switchView('heatmap')">Heatmap</button>
                    <!-- Split view (#33) -->
                    <button class="view-btn" id="view-split" onclick="switchView('split')">Split</button>
                </div>

                <div class="calendar-grid" id="calendar"></div>
            </div>
        </div>
    </div>

    <!-- Focus mode toggle (#86) -->
    <div style="text-align:center;margin:0.3rem auto;max-width:1100px;">
        <button class="export-btn" id="focusModeBtn" onclick="toggleFocusMode()">&#127775; Focus Mode</button>
        <button class="export-btn" onclick="showPomodoroTimer()">&#9200; Pomodoro</button>
        <button class="export-btn" onclick="showHabitTracker()">&#9989; Habits</button>
        <button class="export-btn" onclick="showWeeklySummary()">&#128203; Weekly Report</button>
        <button class="export-btn" onclick="showStatsDashboard()">&#128200; Stats</button>
        <button class="export-btn" onclick="showPublicHolidays()">&#127881; Holidays</button>
        <button class="export-btn" onclick="showQuickAdd()">&#9889; Quick Add</button>
    </div>

    <!-- Conflict warning -->
    <?php if (isset($_GET['warning']) && $_GET['warning'] === 'conflict'): ?>
    <div class="alert" style="background:#fef9c3;color:#854d0e;max-width:700px;margin:1rem auto;padding:1rem;border-radius:6px;text-align:center;font-weight:bold;">
        &#9888;&#65039; Warning: This event may conflict with an existing event.
    </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <!-- Undo banner -->
    <?php if ($showUndo): ?>
    <div class="alert alert-success" style="display:flex;align-items:center;justify-content:center;gap:1rem;">
        <span>Event deleted.</span>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="undo_delete">
            <button type="submit" style="background:#065f46;color:white;border:none;border-radius:6px;padding:6px 16px;cursor:pointer;font-size:0.9rem;">Undo</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Main Event Modal ── -->
    <div class="modal" id="eventModal">
        <div class="modal-content" style="max-width:520px;">
            <!-- Template selector -->
            <div style="margin-bottom:0.5rem;">
                <select id="templateSelect" style="width:100%;padding:8px;font-size:0.9rem;border:1px solid #ccc;border-radius:5px;" onchange="applyTemplate(this.value)">
                    <option value="">-- Apply template --</option>
                </select>
            </div>

            <div id="eventSelectorWrapper" style="display:none">
                <label for="eventSelector"><strong>Select Event</strong></label>
                <select id="eventSelector" onchange="handleEventSelection(this.value)"></select>
            </div>

            <div id="conflictWarning" style="display:none;background:#fef9c3;color:#854d0e;padding:8px 12px;border-radius:6px;margin-bottom:0.5rem;font-size:0.9rem;">
                &#9888;&#65039; This event conflicts with an existing event.
            </div>

            <!-- Tabs for modal sections -->
            <div style="display:flex;gap:4px;margin-bottom:1rem;border-bottom:2px solid #e5e7eb;flex-wrap:wrap;">
                <button type="button" class="modal-tab active" onclick="switchModalTab('details')">Details</button>
                <button type="button" class="modal-tab" onclick="switchModalTab('extra')">Extra</button>
                <button type="button" class="modal-tab" onclick="switchModalTab('subtasks')">Tasks</button>
                <button type="button" class="modal-tab" onclick="switchModalTab('subevents')">Sub-events</button>
                <button type="button" class="modal-tab" onclick="switchModalTab('sharing')">Sharing</button>
                <button type="button" class="modal-tab" onclick="switchModalTab('history')">History</button>
                <button type="button" class="modal-tab" onclick="switchModalTab('comments')">Comments</button>
            </div>

            <form method="POST" id="eventForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add" id="formAction">
                <input type="hidden" name="event_id" id="eventID">
                <input type="hidden" name="occurrence_date" id="occurrenceDate">
                <!-- Optimistic locking version (#59) -->
                <input type="hidden" name="version" id="eventVersion" value="0">

                <!-- TAB: Details -->
                <div id="tab-details" class="modal-tab-content">
                    <label for="courseName">Event Title</label>
                    <input type="text" name="course_name" id="courseName" required>

                    <label for="instructorName">Instructor / Organizer</label>
                    <input type="text" name="instructor_name" id="instructorName" required>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><label for="startDate">Start Date</label><input type="date" name="start_date" id="startDate" required></div>
                        <div><label for="endDate">End Date</label><input type="date" name="end_date" id="endDate" required></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><label for="startTime">Start Time</label><input type="time" name="start_time" id="startTime"></div>
                        <div><label for="endTime">End Time</label><input type="time" name="end_time" id="endTime"></div>
                    </div>

                    <label for="recurrence">Repeat</label>
                    <select name="recurrence" id="recurrence" onchange="toggleRecurrenceEnd(this.value)">
                        <option value="none">None</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>

                    <div id="recurrenceEndWrapper" style="display:none;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div><label for="recurrenceEnd">Repeat Until</label><input type="date" name="recurrence_end" id="recurrenceEnd"></div>
                            <div><label for="recurrenceCount">Or After N occurrences</label><input type="number" name="recurrence_count" id="recurrenceCount" min="1" placeholder="e.g. 10"></div>
                        </div>
                    </div>

                    <div id="editScopeWrapper" style="display:none;margin-top:1rem;">
                        <label><strong>Edit scope</strong></label>
                        <div class="radio-group">
                            <label><input type="radio" name="edit_scope" value="all" checked> All occurrences</label>
                            <label><input type="radio" name="edit_scope" value="this"> This occurrence only</label>
                            <label><input type="radio" name="edit_scope" value="future"> This and all future</label>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div>
                            <label for="eventColor">Color</label>
                            <input type="color" name="color" id="eventColor" value="#6B82F6" style="height:40px;padding:2px 4px;">
                        </div>
                        <div>
                            <label for="eventPriority">Priority</label>
                            <select name="priority" id="eventPriority">
                                <option value="1">&#128994; Low</option>
                                <option value="2">&#128992; Medium</option>
                                <option value="3">&#128308; High</option>
                            </select>
                        </div>
                    </div>

                    <label for="eventCategory">Category</label>
                    <input type="text" name="category" id="eventCategory" placeholder="e.g. Work, Personal...">

                    <!-- Event status (#28) -->
                    <label for="eventStatus">Status</label>
                    <select name="status" id="eventStatus">
                        <option value="confirmed">&#9989; Confirmed</option>
                        <option value="tentative">&#128336; Tentative</option>
                        <option value="cancelled">&#10060; Cancelled</option>
                    </select>

                    <!-- Visibility (#27) -->
                    <label for="eventVisibility">Visibility</label>
                    <select name="visibility" id="eventVisibility">
                        <option value="public">&#127760; Public</option>
                        <option value="private">&#128274; Private</option>
                    </select>

                    <label for="eventNotes">Notes (Markdown supported)</label>
                    <textarea name="notes" id="eventNotes" rows="3" style="width:100%;padding:10px;font-size:1rem;border:1px solid #ccc;border-radius:5px;resize:vertical;font-family:monospace;" oninput="updateNotesPreview()"></textarea>
                    <div id="notesPreview" style="display:none;border:1px solid #e5e7eb;border-radius:5px;padding:10px;font-size:0.9rem;min-height:60px;background:#f9fafb;"></div>
                    <button type="button" onclick="toggleNotesPreview()" style="font-size:0.75rem;background:none;border:1px solid #ccc;border-radius:4px;padding:3px 8px;cursor:pointer;margin-top:4px;width:auto;margin-bottom:4px;">&#128065; Preview</button>
                </div>

                <!-- TAB: Extra -->
                <div id="tab-extra" class="modal-tab-content" style="display:none;">
                    <label for="eventUrl">Event Link (URL)</label>
                    <input type="url" name="event_url" id="eventUrl" placeholder="https://...">

                    <!-- Zoom URL (#73) -->
                    <label for="eventZoomUrl">Zoom / Meeting URL</label>
                    <input type="url" name="zoom_url" id="eventZoomUrl" placeholder="https://zoom.us/j/...">
                    <div id="zoomJoinBtn" style="display:none;margin-top:4px;"></div>

                    <!-- Location (#25) -->
                    <label for="eventLocation">Location</label>
                    <input type="text" name="location" id="eventLocation" placeholder="Address or place name..." oninput="updateLocationLink()">
                    <div id="locationLink" style="font-size:0.8rem;margin-top:2px;"></div>

                    <!-- Tags (#30) -->
                    <label for="eventTags">Tags (comma-separated)</label>
                    <input type="text" name="tags" id="eventTags" placeholder="e.g. important, meeting, follow-up...">

                    <!-- Attendees (#24) -->
                    <label for="eventAttendees">Attendees (comma-separated emails)</label>
                    <input type="text" name="attendees" id="eventAttendees" placeholder="alice@example.com, bob@example.com">
                    <!-- Invite button (#49) -->
                    <button type="button" onclick="sendInviteEmails(document.getElementById('eventID').value)" style="font-size:0.78rem;background:var(--primary);color:white;border:none;border-radius:4px;padding:4px 10px;cursor:pointer;margin-top:4px;">&#9993; Send Invites</button>

                    <!-- RSVP status display (#26) -->
                    <div id="rsvpDisplay" style="font-size:0.82rem;margin-top:4px;"></div>

                    <!-- Capacity (#29) -->
                    <label for="eventCapacity">Seat Capacity</label>
                    <input type="number" name="capacity" id="eventCapacity" min="0" placeholder="Leave empty for unlimited">

                    <!-- Deadline countdown (#85) -->
                    <label for="eventDeadline">Deadline</label>
                    <input type="datetime-local" name="deadline" id="eventDeadline">
                    <div id="deadlineCountdown" style="font-size:0.82rem;margin-top:2px;color:#b91c1c;"></div>

                    <!-- Calendar selector (#53) -->
                    <label for="eventCalendar">Calendar</label>
                    <select name="calendar_id" id="eventCalendar">
                        <option value="">Default</option>
                        <?php foreach ($userCalendars as $cal): ?>
                        <option value="<?= $cal['id'] ?>"><?= htmlspecialchars($cal['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Reminders (#91, #96 custom message) -->
                    <label for="eventReminders">Reminders (JSON: min + optional message)</label>
                    <input type="text" name="reminders" id="eventReminders" placeholder='[{"min":15,"message":"Time to prep!"},{"min":60}]'>
                    <div id="remindersPreview" style="font-size:0.78rem;color:#6b7280;margin-top:2px;min-height:16px;"></div>

                    <!-- Related events (#31) -->
                    <label for="eventRelatedIds">Related Event IDs (comma-separated)</label>
                    <input type="text" name="related_ids" id="eventRelatedIds" placeholder="e.g. 12, 34, 56">
                    <div id="relatedEventsList" style="margin-top:4px;"></div>

                    <!-- Attachment -->
                    <label for="eventAttachment">Attachment</label>
                    <input type="file" name="attachment" id="eventAttachment" accept="*/*" style="padding:6px;">
                    <div id="currentAttachment" style="font-size:0.8rem;margin-top:2px;color:#6b7280;"></div>

                    <!-- Actual time logging (#88) -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:0.5rem;">
                        <div><label for="eventActualStart">Actual Start Time</label><input type="time" name="actual_start" id="eventActualStart"></div>
                        <div><label for="eventActualEnd">Actual End Time</label><input type="time" name="actual_end" id="eventActualEnd"></div>
                    </div>
                    <div id="actualVsPlanned" style="font-size:0.82rem;margin-top:4px;color:#6b7280;"></div>
                </div>

                <!-- TAB: Subtasks/Checklist (#22) -->
                <div id="tab-subtasks" class="modal-tab-content" style="display:none;">
                    <div style="font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;">Add checklist items (one per line)</div>
                    <textarea name="subtasks" id="eventSubtasks" rows="6" style="width:100%;padding:10px;font-size:0.9rem;border:1px solid #ccc;border-radius:5px;resize:vertical;font-family:'Inter',sans-serif;" placeholder="[ ] Buy supplies&#10;[ ] Send invites&#10;[x] Book venue"></textarea>
                    <div id="subtasksChecklist" style="margin-top:0.5rem;"></div>
                </div>

                <!-- TAB: Sub-events (#61) -->
                <div id="tab-subevents" class="modal-tab-content" style="display:none;">
                    <div style="font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;">Child events / sub-tasks linked to this event.</div>
                    <div id="subEventsContent" style="max-height:180px;overflow-y:auto;margin-bottom:0.5rem;"></div>
                    <!-- Add sub-event inline form -->
                    <div id="addSubEventForm" style="border-top:1px solid #e5e7eb;padding-top:0.5rem;margin-top:0.5rem;">
                        <div style="font-weight:bold;font-size:0.82rem;margin-bottom:4px;">+ Add Sub-event</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <input type="text" id="subEventTitle" placeholder="Sub-event title..." style="flex:1;min-width:120px;padding:5px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                            <input type="date" id="subEventDate" style="padding:5px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                            <button type="button" onclick="addSubEvent()" style="padding:5px 10px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.82rem;">Add</button>
                        </div>
                    </div>
                </div>

                <!-- TAB: Sharing / Permissions (#62) -->
                <div id="tab-sharing" class="modal-tab-content" style="display:none;">
                    <div style="font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;">Grant other users access to this event.</div>
                    <div id="eventPermissionsContent" style="max-height:160px;overflow-y:auto;margin-bottom:0.5rem;"></div>
                    <div style="border-top:1px solid #e5e7eb;padding-top:0.5rem;margin-top:0.5rem;">
                        <div style="font-weight:bold;font-size:0.82rem;margin-bottom:4px;">Grant Access</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <input type="text" id="grantUsername" placeholder="Username..." style="flex:1;min-width:100px;padding:5px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                            <select id="grantPermission" style="padding:5px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                                <option value="view">View</option>
                                <option value="edit">Edit</option>
                            </select>
                            <button type="button" onclick="grantPermission(document.getElementById('eventID').value, document.getElementById('grantUsername').value, document.getElementById('grantPermission').value)" style="padding:5px 10px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.82rem;">Grant</button>
                        </div>
                    </div>
                </div>

                <!-- TAB: History (#35) -->
                <div id="tab-history" class="modal-tab-content" style="display:none;">
                    <div id="historyContent" style="font-size:0.82rem;color:#374151;max-height:200px;overflow-y:auto;"></div>
                </div>

                <!-- TAB: Comments (#63) -->
                <div id="tab-comments" class="modal-tab-content" style="display:none;">
                    <div id="commentsContent" style="font-size:0.82rem;max-height:160px;overflow-y:auto;margin-bottom:0.5rem;"></div>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="newCommentText" placeholder="Add a comment..." style="flex:1;padding:6px 10px;font-size:0.85rem;border:1px solid #ccc;border-radius:4px;">
                        <button type="button" onclick="submitComment()" style="width:auto;padding:6px 12px;font-size:0.85rem;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;">Post</button>
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-top:1rem;flex-wrap:wrap;">
                    <button type="submit" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;font-size:1rem;cursor:pointer;">&#128190; Save</button>
                    <button type="button" id="saveTemplateBtn" onclick="saveAsTemplate()" style="background:#6B82F6;color:white;border:none;border-radius:6px;padding:10px;font-size:0.9rem;cursor:pointer;flex:1;">Template</button>
                    <button type="button" id="duplicateBtn" style="display:none;background:#6B82F6;color:white;border:none;border-radius:6px;padding:10px;font-size:0.9rem;cursor:pointer;flex:1;" onclick="duplicateEventDialog()">&#128258; Dup</button>
                </div>
            </form>

            <!-- Delete Form -->
            <form method="POST" id="deleteForm" onsubmit="return false" style="margin-top:0.5rem;display:flex;gap:8px;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="event_id" id="deleteEventID">
                <button type="button" style="flex:1;background:crimson;color:white;border:none;border-radius:6px;padding:8px;font-size:0.9rem;cursor:pointer;" onclick="confirmDeleteEvent()">&#128465; Delete</button>
                <button type="button" onclick="closeModal()" style="flex:1;background:#e5e7eb;color:#333;border:none;border-radius:6px;padding:8px;font-size:0.9rem;cursor:pointer;">&#10005; Cancel</button>
            </form>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="modal" id="confirmModal">
        <div class="modal-content" style="max-width:340px;">
            <p id="confirmMessage" style="margin-bottom:1rem;font-size:1rem;"></p>
            <div class="confirm-buttons">
                <button class="confirm-btn-yes" id="confirmYesBtn">Yes</button>
                <button class="confirm-btn-no" onclick="hideConfirmModal()">No</button>
            </div>
        </div>
    </div>

    <!-- Shortcuts modal (#4) -->
    <div class="modal" id="shortcutsModal">
        <div class="modal-content" style="max-width:480px;">
            <h3 style="margin-bottom:1rem;">&#9000; Keyboard Shortcuts</h3>
            <table style="width:100%;font-size:0.9rem;border-collapse:collapse;">
                <tr><td style="padding:6px;font-weight:bold;width:120px;">Arrow Left/Right</td><td style="padding:6px;">Previous/Next period</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:6px;font-weight:bold;">T</td><td style="padding:6px;">Go to Today</td></tr>
                <tr><td style="padding:6px;font-weight:bold;">N</td><td style="padding:6px;">New event</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:6px;font-weight:bold;">/</td><td style="padding:6px;">Focus search bar</td></tr>
                <tr><td style="padding:6px;font-weight:bold;">?</td><td style="padding:6px;">This help modal</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:6px;font-weight:bold;">Escape</td><td style="padding:6px;">Close modal/panel</td></tr>
                <tr><td style="padding:6px;font-weight:bold;">1</td><td style="padding:6px;">Monthly view</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:6px;font-weight:bold;">2</td><td style="padding:6px;">Weekly view</td></tr>
                <tr><td style="padding:6px;font-weight:bold;">3</td><td style="padding:6px;">Daily view</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:6px;font-weight:bold;">4</td><td style="padding:6px;">Agenda view</td></tr>
                <tr><td style="padding:6px;font-weight:bold;">D</td><td style="padding:6px;">Toggle dark mode</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:6px;font-weight:bold;">Ctrl+Z</td><td style="padding:6px;">Undo last drag/resize</td></tr>
                <tr><td style="padding:6px;font-weight:bold;">F</td><td style="padding:6px;">Toggle focus mode</td></tr>
            </table>
            <button onclick="closeShortcutsModal()" style="margin-top:1rem;width:100%;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Close</button>
        </div>
    </div>

    <!-- Pomodoro timer modal (#87) -->
    <div class="modal" id="pomodoroModal">
        <div class="modal-content" style="max-width:320px;text-align:center;">
            <h3>&#9200; Pomodoro Timer</h3>
            <div id="pomodoroDisplay" style="font-size:3rem;font-weight:bold;margin:1rem 0;color:var(--primary);">25:00</div>
            <div id="pomodoroStatus" style="font-size:0.9rem;color:#6b7280;margin-bottom:1rem;">Work session</div>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                <button onclick="startPomodoro()" style="background:var(--primary);color:white;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;">Start</button>
                <button onclick="pausePomodoro()" style="background:#6b7280;color:white;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;">Pause</button>
                <button onclick="resetPomodoro()" style="background:#e5e7eb;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;">Reset</button>
            </div>
            <div style="margin-top:1rem;font-size:0.82rem;color:#6b7280;">Sessions completed: <span id="pomodoroCount">0</span></div>
            <button onclick="closePomodoroModal()" style="margin-top:1rem;width:100%;background:#e5e7eb;border:none;border-radius:6px;padding:8px;cursor:pointer;">Close</button>
        </div>
    </div>

    <!-- Duplicate dialog (#37) -->
    <div class="modal" id="duplicateDialog">
        <div class="modal-content" style="max-width:340px;">
            <h3>&#128258; Duplicate Event</h3>
            <label style="display:block;margin-top:1rem;">Offset (days): <input type="number" id="dupOffsetDays" value="1" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;"></label>
            <div style="display:flex;gap:8px;margin-top:1rem;">
                <button onclick="confirmDuplicate()" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;cursor:pointer;">Duplicate</button>
                <button onclick="closeDuplicateDialog()" style="flex:1;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Move event dialog (#38) -->
    <div class="modal" id="moveEventDialog">
        <div class="modal-content" style="max-width:340px;">
            <h3>&#8594; Move Event</h3>
            <label style="display:block;margin-top:1rem;">New Start Date: <input type="date" id="moveNewStart" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;"></label>
            <label style="display:block;margin-top:0.5rem;">New End Date: <input type="date" id="moveNewEnd" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;"></label>
            <div style="display:flex;gap:8px;margin-top:1rem;">
                <button onclick="confirmMoveEvent()" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;cursor:pointer;">Move</button>
                <button onclick="closeMoveDialog()" style="flex:1;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Quick add natural language modal (#36) -->
    <div class="modal" id="quickAddModal">
        <div class="modal-content" style="max-width:400px;">
            <h3>&#9889; Quick Add</h3>
            <p style="font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;">Type naturally: "Meeting tomorrow at 3pm", "Dentist Friday 9am-10am"</p>
            <input type="text" id="quickAddInput" placeholder="Describe your event..." style="width:100%;padding:10px;border:1px solid #ccc;border-radius:5px;font-size:1rem;" onkeydown="if(event.key==='Enter')parseQuickAdd()">
            <div id="quickAddPreview" style="margin-top:0.5rem;font-size:0.85rem;background:#f9fafb;padding:8px;border-radius:4px;display:none;"></div>
            <div style="display:flex;gap:8px;margin-top:1rem;">
                <button onclick="parseQuickAdd()" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;cursor:pointer;">Parse & Add</button>
                <button onclick="closeQuickAddModal()" style="flex:1;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Create calendar modal (#51) -->
    <div class="modal" id="createCalendarModal">
        <div class="modal-content" style="max-width:340px;">
            <h3>&#128197; Create Calendar</h3>
            <form method="POST" id="createCalForm">
                <input type="hidden" name="action" value="create_calendar">
                <label style="display:block;margin-top:0.5rem;">Name: <input type="text" name="cal_name" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;"></label>
                <label style="display:block;margin-top:0.5rem;">Color: <input type="color" name="cal_color" value="#6B82F6" style="height:36px;width:60px;padding:2px;margin-top:4px;vertical-align:middle;"></label>
                <label style="display:block;margin-top:0.5rem;">Description: <textarea name="cal_desc" rows="2" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;resize:none;"></textarea></label>
                <div style="display:flex;gap:8px;margin-top:1rem;">
                    <button type="submit" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;cursor:pointer;">Create</button>
                    <button type="button" onclick="closeCreateCalendarModal()" style="flex:1;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Weekly summary modal (#90) -->
    <div class="modal" id="weeklySummaryModal">
        <div class="modal-content" style="max-width:600px;">
            <h3>&#128203; Weekly Summary Report</h3>
            <div id="weeklySummaryContent" style="font-size:0.9rem;"></div>
            <div style="display:flex;gap:8px;margin-top:1rem;">
                <button onclick="window.print()" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;cursor:pointer;">&#128424; Print</button>
                <button onclick="closeWeeklySummary()" style="flex:1;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Close</button>
            </div>
        </div>
    </div>

    <!-- Stats modal (#83) -->
    <div class="modal" id="statsModal">
        <div class="modal-content" style="max-width:600px;">
            <h3>&#128200; Statistics Dashboard</h3>
            <div id="statsContent" style="font-size:0.9rem;"></div>
            <button onclick="closeStatsModal()" style="margin-top:1rem;width:100%;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Close</button>
        </div>
    </div>

    <!-- Habit tracker modal (#89) -->
    <div class="modal" id="habitModal">
        <div class="modal-content" style="max-width:500px;">
            <h3>&#9989; Habit Tracker</h3>
            <p style="font-size:0.85rem;color:#6b7280;">Daily recurring events this week:</p>
            <div id="habitContent" style="font-size:0.9rem;"></div>
            <button onclick="closeHabitModal()" style="margin-top:1rem;width:100%;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Close</button>
        </div>
    </div>

    <!-- Context menu -->
    <div id="contextMenu" style="display:none;position:fixed;background:white;box-shadow:0 4px 16px rgba(0,0,0,0.15);border-radius:8px;z-index:10000;min-width:160px;overflow:hidden;">
        <button onclick="contextMenuEdit()" style="display:block;width:100%;padding:10px 16px;border:none;background:none;text-align:left;cursor:pointer;font-size:0.9rem;">&#9998; Edit</button>
        <button onclick="contextMenuDuplicate()" style="display:block;width:100%;padding:10px 16px;border:none;background:none;text-align:left;cursor:pointer;font-size:0.9rem;">&#128258; Duplicate</button>
        <button onclick="contextMenuMoveEvent()" style="display:block;width:100%;padding:10px 16px;border:none;background:none;text-align:left;cursor:pointer;font-size:0.9rem;">&#8594; Move to Date</button>
        <button onclick="contextMenuSkipOccurrence()" style="display:block;width:100%;padding:10px 16px;border:none;background:none;text-align:left;cursor:pointer;font-size:0.9rem;">&#9940; Skip Occurrence</button>
        <button onclick="contextMenuOpenZoom()" style="display:block;width:100%;padding:10px 16px;border:none;background:none;text-align:left;cursor:pointer;font-size:0.9rem;">&#127909; Open Meeting</button>
        <button onclick="contextMenuDelete()" style="display:block;width:100%;padding:10px 16px;border:none;background:none;text-align:left;cursor:pointer;font-size:0.9rem;color:#b91c1c;">&#128465; Delete</button>
    </div>

    <!-- Spinner overlay -->
    <div id="spinnerOverlay" style="display:none;">
        <div class="spinner"></div>
    </div>

    <!-- Dev tools -->
    <?php if (DEV_MODE): ?>
    <div style="text-align:center; margin: 0.5rem auto 1.5rem;">
        <form method="POST" id="deleteAllForm" onsubmit="return false" style="display:inline;">
            <input type="hidden" name="action" value="delete_all">
            <button type="button" style="background:#b91c1c;color:white;border:none;border-radius:6px;padding:7px 18px;font-size:0.85rem;cursor:pointer;" onclick="confirmDeleteAll()">&#128465; Delete all events</button>
        </form>
        &nbsp;
        <a href="index.php?action=export_sql" class="export-btn">&#128190; Export DB Backup</a>
        &nbsp;
        <form method="POST" enctype="multipart/form-data" style="display:inline;">
            <input type="hidden" name="action" value="restore_sql">
            <label class="export-btn" style="cursor:pointer;">&#128257; Restore DB
                <input type="file" name="sql_file" accept=".sql" style="display:none" onchange="showSpinner(); this.form.submit();">
            </label>
        </form>
        &nbsp;
        <a href="api.php?action=list_events" class="export-btn">&#128279; API Endpoint</a>
        &nbsp;
        <a href="profile.php" class="export-btn">&#128100; Profile</a>
    </div>
    <?php endif; ?>

    <!-- Share calendar modal (#57, #58) -->
    <div class="modal" id="shareCalendarModal">
        <div class="modal-content" style="max-width:520px;">
            <h3>&#128279; Share Calendar</h3>
            <p style="font-size:0.85rem;color:#6b7280;">Share a read-only view or embed your calendar on any page.</p>
            <label style="display:block;margin-top:0.8rem;font-weight:bold;font-size:0.85rem;">Read-only Calendar URL (#57):</label>
            <div style="display:flex;gap:6px;margin-top:4px;">
                <input type="text" id="shareUrlDisplay" readonly style="flex:1;padding:6px 10px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;background:#f9fafb;">
                <button onclick="copyShareUrl()" class="export-btn" style="white-space:nowrap;">&#128203; Copy</button>
            </div>
            <label style="display:block;margin-top:0.8rem;font-weight:bold;font-size:0.85rem;">Embed iframe code (#58):</label>
            <div style="display:flex;gap:6px;margin-top:4px;">
                <textarea id="embedCodeDisplay" readonly rows="3" style="flex:1;padding:6px 10px;font-size:0.78rem;border:1px solid #ccc;border-radius:4px;background:#f9fafb;resize:none;font-family:monospace;"></textarea>
                <button onclick="copyEmbedCode()" class="export-btn" style="white-space:nowrap;align-self:flex-start;">&#128203; Copy</button>
            </div>
            <div style="font-size:0.75rem;color:#9ca3af;margin-top:6px;">The share token is stored locally. Anyone with this link can view your events (read-only).</div>
            <button onclick="closeShareCalendarModal()" style="margin-top:1rem;width:100%;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Close</button>
        </div>
    </div>

    <!-- CSV field mapping dialog (#76) -->
    <div class="modal" id="csvMappingModal">
        <div class="modal-content" style="max-width:480px;">
            <h3>&#128202; CSV Field Mapping</h3>
            <div id="csvMappingFields" style="max-height:340px;overflow-y:auto;font-size:0.85rem;"></div>
            <div style="display:flex;gap:8px;margin-top:1rem;">
                <button onclick="applyCsvMapping()" style="flex:1;background:var(--primary);color:white;border:none;border-radius:6px;padding:10px;cursor:pointer;">Import Mapped</button>
                <button onclick="closeCsvMappingModal()" style="flex:1;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Settings panel -->
    <div id="settingsPanel" style="display:none;position:fixed;background:white;box-shadow:0 4px 20px rgba(0,0,0,0.15);padding:1.2rem 1.5rem;border-radius:10px;z-index:9500;min-width:300px;top:120px;right:20px;max-height:80vh;overflow-y:auto;">
        <strong style="display:block;margin-bottom:0.8rem;font-size:1rem;">&#9881;&#65039; Settings</strong>
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">First day of week:</span><br>
            <label style="font-size:0.85rem;"><input type="radio" name="firstDay" value="0" onchange="saveSetting('firstDay','0');renderCalendar(currentDate)"> Sun</label>
            &nbsp;
            <label style="font-size:0.85rem;"><input type="radio" name="firstDay" value="1" onchange="saveSetting('firstDay','1');renderCalendar(currentDate)"> Mon</label>
        </div>
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">Clock format:</span><br>
            <label style="font-size:0.85rem;"><input type="radio" name="clockFmt" value="24" onchange="saveSetting('clockFmt','24')"> 24h</label>
            &nbsp;
            <label style="font-size:0.85rem;"><input type="radio" name="clockFmt" value="12" onchange="saveSetting('clockFmt','12')"> 12h</label>
        </div>
        <div style="margin-bottom:0.7rem;">
            <label style="font-size:0.85rem;font-weight:bold;">Date format:</label><br>
            <select style="font-size:0.85rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;" onchange="saveSetting('dateFmt',this.value)">
                <option value="MDY">MM/DD/YYYY</option>
                <option value="DMY">DD/MM/YYYY</option>
            </select>
        </div>
        <div style="margin-bottom:0.7rem;">
            <label style="font-size:0.85rem;font-weight:bold;">Color theme:</label><br>
            <select id="themeSelect" style="font-size:0.85rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;" onchange="applyTheme(this.value)">
                <option value="blue">Blue</option>
                <option value="purple">Purple</option>
                <option value="green">Green</option>
                <option value="orange">Orange</option>
            </select>
        </div>
        <!-- Snap interval (#6) -->
        <div style="margin-bottom:0.7rem;">
            <label style="font-size:0.85rem;font-weight:bold;">Snap interval (min):</label><br>
            <select style="font-size:0.85rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;margin-top:4px;" onchange="saveSetting('snapMin',this.value)" id="snapMinSelect">
                <option value="5">5 min</option>
                <option value="10">10 min</option>
                <option value="15" selected>15 min</option>
                <option value="30">30 min</option>
            </select>
        </div>
        <!-- Quiet hours (#94) -->
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">Quiet hours (suppress notifications):</span><br>
            <label style="font-size:0.82rem;">From: <input type="time" id="quietHoursStart" style="font-size:0.82rem;padding:2px 4px;border:1px solid #ccc;border-radius:4px;" onchange="saveSetting('quietStart',this.value)" value="22:00"></label>
            <label style="font-size:0.82rem;margin-left:8px;">To: <input type="time" id="quietHoursEnd" style="font-size:0.82rem;padding:2px 4px;border:1px solid #ccc;border-radius:4px;" onchange="saveSetting('quietEnd',this.value)" value="08:00"></label>
        </div>
        <!-- Auto-archive (#34) -->
        <?php if (DEV_MODE): ?>
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">Auto-archive past events older than:</span><br>
            <form method="POST" style="display:flex;gap:4px;align-items:center;margin-top:4px;">
                <input type="hidden" name="action" value="auto_archive">
                <input type="number" name="archive_days" value="365" min="1" style="width:70px;padding:4px 6px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                <span style="font-size:0.82rem;">days</span>
                <button type="submit" style="font-size:0.82rem;padding:4px 8px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;">Archive</button>
            </form>
        </div>
        <?php endif; ?>
        <!-- Webhook settings (#69) -->
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">Webhook URL:</span><br>
            <input type="url" id="webhookUrlInput" placeholder="https://..." style="font-size:0.82rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;width:100%;margin-top:4px;">
            <button onclick="saveWebhook()" style="font-size:0.82rem;padding:4px 8px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;margin-top:4px;">Save Webhook</button>
        </div>
        <!-- Timezone setting (#100) -->
        <div style="margin-bottom:0.7rem;">
            <label style="font-size:0.85rem;font-weight:bold;">Display timezone:</label><br>
            <select id="tzSelect" style="font-size:0.82rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;width:100%;margin-top:4px;" onchange="saveSetting('timezone',this.value)">
                <option value="local">Local (browser)</option>
                <option value="UTC">UTC</option>
                <option value="America/New_York">Eastern (ET)</option>
                <option value="America/Chicago">Central (CT)</option>
                <option value="America/Denver">Mountain (MT)</option>
                <option value="America/Los_Angeles">Pacific (PT)</option>
                <option value="Europe/London">London</option>
                <option value="Europe/Paris">Paris</option>
                <option value="Asia/Tokyo">Tokyo</option>
            </select>
        </div>
        <!-- Color hour blocks (#18) -->
        <div style="margin-bottom:0.7rem;">
            <label style="font-size:0.85rem;"><input type="checkbox" id="colorHoursToggle" onchange="saveSetting('colorHours',this.checked?'1':'0');renderCalendar(currentDate)"> Color-code hour blocks</label>
        </div>
        <!-- Webhook test (#69, #80) -->
        <div style="margin-bottom:0.7rem;">
            <button onclick="testWebhookTrigger()" style="font-size:0.82rem;padding:4px 8px;background:#e5e7eb;border:none;border-radius:4px;cursor:pointer;width:100%;">&#128279; Test Webhook Ping</button>
        </div>
        <!-- SMTP email reminders (#74) -->
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">Email Reminders (SMTP #74):</span><br>
            <input type="email" id="smtpTestEmail" placeholder="test@example.com" style="font-size:0.82rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;width:100%;margin-top:4px;">
            <button onclick="sendTestEmailReminder()" style="font-size:0.82rem;padding:4px 8px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;margin-top:4px;width:100%;">Send Test Email</button>
            <div style="font-size:0.72rem;color:#9ca3af;margin-top:2px;">Configure SMTP in config.php (SMTP_HOST, SMTP_USER, SMTP_PASS, SMTP_FROM)</div>
        </div>
        <!-- SMS reminders via Twilio (#75) -->
        <div style="margin-bottom:0.7rem;">
            <span style="font-size:0.85rem;font-weight:bold;">SMS Reminders (Twilio #75):</span><br>
            <input type="tel" id="twilioTestPhone" placeholder="+15555555555" style="font-size:0.82rem;padding:4px 8px;border:1px solid #ccc;border-radius:4px;width:100%;margin-top:4px;">
            <button onclick="sendTestSmsReminder()" style="font-size:0.82rem;padding:4px 8px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;margin-top:4px;width:100%;">Send Test SMS</button>
            <div style="font-size:0.72rem;color:#9ca3af;margin-top:2px;">Configure Twilio in config.php (TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM)</div>
        </div>
    </div>

    <!-- Calendar Share Modal (#66) -->
    <div class="modal" id="calendarShareModal">
        <div class="modal-content" style="max-width:420px;">
            <h3>&#128279; Share Calendar: <span id="calShareModalTitle"></span></h3>
            <div id="calShareList" style="max-height:160px;overflow-y:auto;margin-bottom:0.5rem;font-size:0.85rem;border:1px solid #e5e7eb;border-radius:6px;padding:0.4rem;min-height:40px;"></div>
            <div style="border-top:1px solid #e5e7eb;padding-top:0.5rem;">
                <div style="font-weight:bold;font-size:0.82rem;margin-bottom:4px;">Add Share</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <input type="text" id="calShareUsername" placeholder="Username..." style="flex:1;min-width:100px;padding:5px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                    <select id="calSharePermission" style="padding:5px 8px;font-size:0.82rem;border:1px solid #ccc;border-radius:4px;">
                        <option value="view">View</option>
                        <option value="edit">Edit</option>
                    </select>
                    <button type="button" onclick="submitCalendarShare()" style="padding:5px 10px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.82rem;">Share</button>
                </div>
            </div>
            <div style="font-size:0.75rem;color:#9ca3af;margin-top:6px;">Grant other users access to this calendar.</div>
            <button onclick="closeCalendarShareModal()" style="margin-top:0.8rem;width:100%;background:#e5e7eb;border:none;border-radius:6px;padding:10px;cursor:pointer;font-size:0.9rem;">Close</button>
        </div>
    </div>

    <script>
        const events = <?php echo json_encode($eventsFromDB, JSON_UNESCAPED_UNICODE); ?>;
        const publicHolidays = <?php echo json_encode($publicHolidays, JSON_UNESCAPED_UNICODE); ?>;
        const userCalendars = <?php echo json_encode($userCalendars, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="calendar.js"></script>
</body>
</html>
