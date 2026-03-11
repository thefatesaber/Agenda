# Google Calendar Clone — Build Plan

Full-stack project using PHP, MySQL, JavaScript, HTML, CSS.

---

## STEP 1 — Setup

1. Install and start XAMPP (or WampServer): start Apache and MySQL.
2. Navigate to `C:\xampp\htdocs` (or `C:\wamp64\www`).
3. Create a new folder named `calendar-project`.
4. Open the folder in Visual Studio Code.

---

## STEP 2 — HTML Structure (`index.php`)

Create `index.php` with the following structure:

### 2.1 Head
- `<!DOCTYPE html>`
- `<html lang="en" dir="ltr">`
- `<head>`
  - `<meta charset="UTF-8">`
  - `<meta name="viewport" content="width=device-width, initial-scale=1.0">`
  - `<title>Calendar Project</title>`
  - `<meta name="description" content="My calendar project">`
  - Google Fonts link for **Inter** (weights 400, 600, 700): `https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap`
  - `<link rel="stylesheet" href="style.css">`
- `</head>`

### 2.2 Body — Header
```html
<header>
  <h1>📅 Course Calendar<br>My Calendar Project</h1>
</header>
```

### 2.3 Body — Clock
```html
<div class="clock-container">
  <div id="clock"></div>
</div>
```

### 2.4 Body — Calendar Section
```html
<div class="calendar">
  <div class="nav-btn-container">
    <div class="nav-btn" onclick="changeMonth(-1)">&#8249;</div>
    <h2 id="monthYear" style="margin: 0;"></h2>
    <button class="nav-btn" onclick="changeMonth(1)">&#8250;</button>
  </div>
  <div class="calendar-grid" id="calendar"></div>
</div>
```

### 2.5 Body — PHP Alert Messages (after calendar div)
```php
<?php if ($successMessage): ?>
  <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
  <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
<?php endif; ?>
```

### 2.6 Body — Modal
Wrap everything below in:
```html
<div class="modal" id="eventModal">
  <div class="modal-content">
    ...
  </div>
</div>
```

#### 2.6.1 Event Selector (for multiple events on same day)
```html
<div id="eventSelectorWrapper" style="display:none;">
  <label for="eventSelector"><strong>Select Event</strong></label>
  <select id="eventSelector" onchange="handleEventSelection(this.value)">
    <option disabled selected>Choose event...</option>
  </select>
</div>
```

#### 2.6.2 Add/Edit Form
```html
<form method="POST" id="eventForm">
  <input type="hidden" name="action" value="add" id="formAction">
  <input type="hidden" name="event_id" id="eventID">

  <label for="courseName">Course Title</label>
  <input type="text" name="course_name" id="courseName" required>

  <label for="instructorName">Instructor Name</label>
  <input type="text" name="instructor_name" id="instructorName" required>

  <label for="startDate">Start Date</label>
  <input type="date" name="start_date" id="startDate" required>

  <label for="endDate">End Date</label>
  <input type="date" name="end_date" id="endDate" required>

  <label for="startTime">Start Time</label>
  <input type="time" name="start_time" id="startTime" required>

  <label for="endTime">End Time</label>
  <input type="time" name="end_time" id="endTime" required>

  <button type="submit">💾 Save</button>
</form>
```

#### 2.6.3 Delete Form
```html
<form method="POST" onsubmit="return confirm('Are you sure you want to delete this appointment?')">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="event_id" id="deleteEventID">
  <button type="submit" class="submit-btn">🗑️ Delete</button>
</form>
```

#### 2.6.4 Cancel Button
```html
<button type="button" onclick="closeModal()" class="cancel-btn">✕ Cancel</button>
```

### 2.7 Body — PHP + JS events injection (before `</body>`)
```html
<script>
  const events = <?php echo json_encode($eventsFromDB, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="calendar.js"></script>
```

### 2.8 PHP include at top of file
At the very top, before `<!DOCTYPE html>`:
```php
<?php include 'calendar.php'; ?>
```

---

## STEP 3 — CSS Styling (`style.css`)

Create `style.css`.

### 3.1 CSS Variables
```css
:root {
  --primary: #6B82F6;
  --primary-light: #dbbefe;
  --primary-dark: #1e3a8a;
  --background: #f9fafb;
  --success: #d1fae5;
  --success-text: #065f46;
  --error: #fee2e2;
  --error-text: #b91c1c;
}
```

### 3.2 Global Reset
```css
* { margin: 0; padding: 0; box-sizing: border-box; }
```

### 3.3 Body
```css
body {
  font-family: 'Inter', sans-serif;
  background-color: var(--background);
  color: #333;
  line-height: 1.6;
}
```

### 3.4 Header
```css
header {
  background: var(--primary);
  color: white;
  padding: 2rem 1rem;
  text-align: center;
}
```

### 3.5 Clock Container
```css
.clock-container {
  background: var(--primary-light);
  color: var(--primary-dark);
  font-size: 2rem;
  font-weight: bold;
  padding: 1rem;
  text-align: center;
  font-family: 'Inter', sans-serif;
  letter-spacing: 2px;
  border-bottom: 2px solid var(--primary);
}

@media (max-width: 768px) {
  .clock-container { font-size: 1.4rem; padding: 0.75rem; }
}
```

### 3.6 Calendar Container
```css
.calendar {
  max-width: 1000px;
  margin: 2rem auto;
  background: white;
  padding: 1.5rem;
  border-radius: 10px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
}
```

### 3.7 Navigation Buttons
```css
.nav-btn-container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.nav-btn {
  font-size: 1.5rem;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--primary-dark);
  font-weight: bold;
}
```

### 3.8 Calendar Grid
```css
.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 10px;
}

/* Mobile: horizontal scroll */
@media (max-width: 1024px) {
  .calendar-grid {
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    gap: 10px;
  }
  .day, .day-name {
    min-width: 140px;
    flex-shrink: 0;
    scroll-snap-align: start;
  }
}
```

### 3.9 Day Names & Day Cells
```css
.day-name { text-align: center; font-weight: bold; font-size: 0.85rem; color: var(--primary-dark); padding: 4px 0; }

.day {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  min-height: 100px;
  padding: 8px;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  position: relative;
  cursor: pointer;
  transition: background 0.2s ease;
}

.day:hover { background: #f3f4f6; }
.day.today { background: var(--primary-light); border-color: var(--primary-dark); }
.date-number { font-weight: bold; margin-bottom: 5px; font-size: 0.9rem; }
```

### 3.10 Event Cards (updated with time feature)
```css
.event {
  background: var(--primary);
  color: white;
  padding: 6px 8px;
  border-radius: 6px;
  margin-top: 6px;
  font-size: 13px;
  cursor: pointer;
  line-height: 1.4;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
  transition: transform 0.15s ease;
}
.event:hover { transform: scale(1.02); }
.event .course { font-weight: bold; font-size: 13px; }
.event .instructor { font-size: 12px; opacity: 0.85; }
.event .time { font-size: 12px; margin-top: 3px; color: #f3f3f3; }
```

### 3.11 Alert Messages
```css
.alert { max-width: 600px; margin: 1rem auto; padding: 1rem; border-radius: 6px; text-align: center; font-weight: bold; }
.alert-success { background: var(--success); color: var(--success-text); }
.alert-error { background: var(--error); color: var(--error-text); }
```

### 3.12 Modal
```css
.modal {
  position: fixed;
  inset: 0;
  display: none;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.5);
  z-index: 9999;
}

.modal-content {
  background: white;
  padding: 2rem;
  border-radius: 10px;
  max-width: 420px;
  width: 90%;
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
  max-height: 90vh;
  overflow-y: auto;
}

.modal-content label { display: block; font-weight: bold; margin-top: 1rem; margin-bottom: 4px; }
.modal-content input[type="text"],
.modal-content input[type="date"],
.modal-content input[type="time"] { width: 100%; padding: 8px 10px; font-size: 1rem; border: 1px solid #ccc; border-radius: 5px; }
.modal-content button[type="submit"] { display: block; margin-top: 1rem; padding: 10px; width: 100%; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; background-color: var(--primary); color: white; }
.submit-btn { display: block; margin-top: 0.75rem; padding: 10px; width: 100%; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; background-color: crimson; color: white; }
.cancel-btn { display: block; margin-top: 0.75rem; padding: 10px; width: 100%; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; background-color: #e5e7eb; color: #333; }
```

### 3.13 Event Selector Dropdown
```css
#eventSelector {
  width: 100%;
  padding: 10px;
  font-size: 1rem;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  border-radius: 5px;
  border: 1px solid #ccc;
}
```

### 3.14 Day Overlay (Add/Edit hover buttons)
```css
.day-overlay { position: absolute; top: 6px; right: 6px; display: none; flex-direction: column; gap: 4px; z-index: 2; }
.day:hover .day-overlay { display: flex; }
.overlay-btn { background: var(--primary-dark); color: white; padding: 3px 7px; font-size: 11px; border: none; border-radius: 4px; cursor: pointer; transition: background 0.2s ease; }
.overlay-btn:hover { background: var(--primary); }
```

---

## STEP 4 — Database Setup

### 4.1 Create the database

Open phpMyAdmin at `http://localhost/phpmyadmin` and run:

```sql
CREATE DATABASE IF NOT EXISTS calendar
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
```

### 4.2 Create the `appointments` table

```sql
USE calendar;

CREATE TABLE IF NOT EXISTS appointments (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  course_name   VARCHAR(255) NOT NULL,
  instructor_name VARCHAR(255) NOT NULL,
  start_date    DATE         NOT NULL,
  end_date      DATE         NOT NULL,
  start_time    TIME         NOT NULL,
  end_time      TIME         NOT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
);
```

> **Note:** `start_time` and `end_time` are added as part of the time feature (Step 8 in tutorial).

---

## STEP 5 — PHP Database Connection (`connection.php`)

Create `connection.php`:

```php
<?php
// Connect to local MySQL/MariaDB server
$connection = new mysqli('localhost', 'root', '', 'calendar');
$connection->set_charset('utf8mb4');
```

> **WampServer users:** The default MariaDB port is 3307, not 3306. Use:
> `$connection = new mysqli('localhost', 'root', '', 'calendar', 3307);`

---

## STEP 6 — PHP Backend Logic (`calendar.php`)

Create `calendar.php`:

### 6.1 Setup
```php
<?php
include 'connection.php';

$successMessage = '';
$errorMessage   = '';
$eventsFromDB   = [];
```

### 6.2 Handle Add
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $course     = trim($_POST['course_name'] ?? '');
    $instructor = trim($_POST['instructor_name'] ?? '');
    $start      = $_POST['start_date'] ?? '';
    $end        = $_POST['end_date'] ?? '';
    $startTime  = $_POST['start_time'] ?? '';
    $endTime    = $_POST['end_time'] ?? '';

    if ($course && $instructor && $start && $end) {
        $stmt = $connection->prepare(
            "INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssss', $course, $instructor, $start, $end, $startTime, $endTime);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
}
```

### 6.3 Handle Edit
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id         = $_POST['event_id'] ?? null;
    $course     = trim($_POST['course_name'] ?? '');
    $instructor = trim($_POST['instructor_name'] ?? '');
    $start      = $_POST['start_date'] ?? '';
    $end        = $_POST['end_date'] ?? '';
    $startTime  = $_POST['start_time'] ?? '';
    $endTime    = $_POST['end_time'] ?? '';

    if ($id && $course && $instructor && $start && $end) {
        $stmt = $connection->prepare(
            "UPDATE appointments
             SET course_name=?, instructor_name=?, start_date=?, end_date=?, start_time=?, end_time=?
             WHERE id=?"
        );
        $stmt->bind_param('ssssssi', $course, $instructor, $start, $end, $startTime, $endTime, $id);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2');
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2');
        exit;
    }
}
```

### 6.4 Handle Delete
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = $_POST['event_id'] ?? null;
    if ($id) {
        $stmt = $connection->prepare("DELETE FROM appointments WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=3');
        exit;
    }
}
```

### 6.5 Success / Error Messages
```php
if (isset($_GET['success'])) {
    $successMessage = match($_GET['success']) {
        '1' => '✅ Appointment added successfully',
        '2' => '✅ Appointment updated successfully',
        '3' => '🗑️ Appointment deleted successfully',
        default => '',
    };
}

if (isset($_GET['error'])) {
    $errorMessage = '❌ An error occurred. Please check your input.';
}
```

### 6.6 Fetch All Appointments (spread over date range)
```php
$result = $connection->query("SELECT * FROM appointments");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $start = new DateTime($row['start_date']);
        $end   = new DateTime($row['end_date']);

        while ($start <= $end) {
            $eventsFromDB[] = [
                'id'         => $row['id'],
                'title'      => $row['course_name'] . ' - ' . $row['instructor_name'],
                'date'       => $start->format('Y-m-d'),
                'start'      => $row['start_date'],
                'end'        => $row['end_date'],
                'start_time' => $row['start_time'],
                'end_time'   => $row['end_time'],
            ];
            $start->modify('+1 day');
        }
    }
}

$connection->close();
```

---

## STEP 7 — JavaScript (`calendar.js`)

Create `calendar.js`:

### 7.1 Element References & State
```js
const calendarEl  = document.getElementById('calendar');
const monthYearEl = document.getElementById('monthYear');
const modalEl     = document.getElementById('eventModal');

let currentDate = new Date();
```

### 7.2 `renderCalendar(date)`
```js
function renderCalendar(date) {
    calendarEl.innerHTML = '';

    const year  = date.getFullYear();
    const month = date.getMonth();
    const today = new Date();
    const totalDays       = new Date(year, month + 1, 0).getDate();
    const firstDayOfMonth = new Date(year, month, 1).getDay();

    monthYearEl.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    // Day name headers
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(day => {
        const el = document.createElement('div');
        el.className = 'day-name';
        el.textContent = day;
        calendarEl.appendChild(el);
    });

    // Empty cells before first day
    for (let i = 0; i < firstDayOfMonth; i++) {
        calendarEl.appendChild(document.createElement('div'));
    }

    // Day cells
    for (let day = 1; day <= totalDays; day++) {
        const dateString = `${year}-${String(month + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;

        const cell = document.createElement('div');
        cell.className = 'day';

        if (day === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
            cell.classList.add('today');
        }

        // Date number
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
        addBtn.onclick = function(e) {
            e.stopPropagation();
            openModalForAdd(dateString);
        };
        overlay.appendChild(addBtn);

        if (eventsToday.length > 0) {
            const editBtn = document.createElement('button');
            editBtn.className = 'overlay-btn';
            editBtn.textContent = 'Edit';
            editBtn.onclick = function(e) {
                e.stopPropagation();
                openModalForEdit(eventsToday);
            };
            overlay.appendChild(editBtn);
        }

        cell.appendChild(overlay);
        calendarEl.appendChild(cell);
    }
}
```

### 7.3 `openModalForAdd(dateString)`
```js
function openModalForAdd(dateString) {
    document.getElementById('formAction').value    = 'add';
    document.getElementById('eventID').value       = '';
    document.getElementById('deleteEventID').value = '';
    document.getElementById('courseName').value    = '';
    document.getElementById('instructorName').value = '';
    document.getElementById('startDate').value     = dateString;
    document.getElementById('endDate').value       = dateString;
    document.getElementById('startTime').value     = '09:00';
    document.getElementById('endTime').value       = '10:00';

    document.getElementById('eventSelectorWrapper').style.display = 'none';
    modalEl.style.display = 'flex';
}
```

### 7.4 `openModalForEdit(eventsOnDate)`
```js
function openModalForEdit(eventsOnDate) {
    document.getElementById('formAction').value = 'edit';

    const selector = document.getElementById('eventSelector');
    const wrapper  = document.getElementById('eventSelectorWrapper');

    selector.innerHTML = '<option disabled selected>Choose event...</option>';
    eventsOnDate.forEach(function(e) {
        const option = document.createElement('option');
        option.value = JSON.stringify(e);
        option.textContent = e.title.split(' - ')[0] + ' (' + e.start + ' \u2192 ' + e.end + ')';
        selector.appendChild(option);
    });

    wrapper.style.display = eventsOnDate.length > 1 ? 'block' : 'none';

    handleEventSelection(JSON.stringify(eventsOnDate[0]));
    modalEl.style.display = 'flex';
}
```

### 7.5 `handleEventSelection(eventJSON)`
```js
function handleEventSelection(eventJSON) {
    const event = JSON.parse(eventJSON);

    document.getElementById('eventID').value       = event.id;
    document.getElementById('deleteEventID').value = event.id;

    const parts = event.title.split(' - ');
    document.getElementById('courseName').value    = parts[0] ? parts[0].trim() : '';
    document.getElementById('instructorName').value = parts[1] ? parts[1].trim() : '';
    document.getElementById('startDate').value     = event.start;
    document.getElementById('endDate').value       = event.end;
    document.getElementById('startTime').value     = event.start_time;
    document.getElementById('endTime').value       = event.end_time;
}
```

### 7.6 `closeModal()`
```js
function closeModal() {
    modalEl.style.display = 'none';
}
```

### 7.7 `changeMonth(offset)`
```js
function changeMonth(offset) {
    currentDate.setMonth(currentDate.getMonth() + offset);
    renderCalendar(currentDate);
}
```

### 7.8 `updateClock()`
```js
function updateClock() {
    const now   = new Date();
    const clock = document.getElementById('clock');
    clock.textContent = [
        String(now.getHours()).padStart(2, '0'),
        String(now.getMinutes()).padStart(2, '0'),
        String(now.getSeconds()).padStart(2, '0')
    ].join(':');
}
```

### 7.9 Initialization
```js
renderCalendar(currentDate);
updateClock();
setInterval(updateClock, 1000);
```

---

## STEP 8 — Time Feature (already included above)

The transcript adds `start_time` / `end_time` as an extension after the initial build. All the steps below are already incorporated into the code above:

- [x] HTML: `start_time` and `end_time` inputs added to the form (Step 2.6.2)
- [x] CSS: `.event` updated with flex layout, sub-elements `.course`, `.instructor`, `.time` (Step 3.10)
- [x] PHP: `start_time` / `end_time` included in INSERT and UPDATE statements (Step 6.2, 6.3)
- [x] PHP: `start_time` / `end_time` included in the events array while loop (Step 6.6)
- [x] JS: `event.start_time` and `event.end_time` rendered on each event card (Step 7.2)
- [x] DB: `start_time TIME NOT NULL` and `end_time TIME NOT NULL` columns in table (Step 4.2)

---

## STEP 9 — Final Integration & Verification

### 9.1 File checklist
- [ ] `index.php` — starts with `<?php include 'calendar.php'; ?>`
- [ ] `calendar.php` — PHP backend (add/edit/delete/fetch)
- [ ] `connection.php` — database connection with correct port
- [ ] `style.css` — all styles
- [ ] `calendar.js` — all JavaScript

### 9.2 Database checklist
- [ ] Database `calendar` exists
- [ ] Table `appointments` exists with columns: `id`, `course_name`, `instructor_name`, `start_date`, `end_date`, `start_time`, `end_time`, `created_at`

### 9.3 Functional tests
| Test | Expected Result |
|------|----------------|
| Load page | Calendar grid renders with day names + day cells |
| Current day | Highlighted with blue background |
| Clock | Ticks every second |
| Navigate months | Prev/next arrows change month and year |
| Reload page | Returns to current month |
| Click day → Add | Modal opens with date pre-filled |
| Submit add form | Success message shown, event spans across date range |
| Hover day with event | Shows "Add" + "Edit" buttons |
| Click Edit → single event | Modal opens with event data pre-filled |
| Click Edit → multiple events | Dropdown appears to select which event |
| Submit edit form | Success message shown, event updated |
| Click Delete | Confirmation popup appears |
| Confirm delete | Success message shown, event removed |
| Submit empty form | Error message shown |
| Events in DB | `appointments` table shows correct rows with times |
