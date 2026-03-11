# Google Calendar Clone — Build Plan

Full-stack project using PHP, MySQL, JavaScript, HTML, CSS.

> **Transcript notes:**
> - The instructor pronounces the font "Inter" as "Enter" throughout — the correct font name is **Inter**.
> - The instructor initially names the database `course_calendar` (timestamp 1:25:34) but then creates it as `calendar` in phpMyAdmin (timestamp 1:27:08). The correct database name used throughout the code is **`calendar`**.
> - The instructor consistently says "model"/"mod" when referring to the modal — the correct CSS class name is **`modal`** / **`modal-content`**.

---

## STEP 1 — Setup

1. Install and start XAMPP (or WampServer): start Apache and MySQL.
2. Navigate to `C:\xampp\htdocs` (or `C:\wamp64\www`).
3. Create a new folder named `calendar-project`.
4. Open the folder in Visual Studio Code.

---

## STEP 2 — HTML Structure (`index.php`)

Create `index.php`.

> **Note:** The `<?php include 'calendar.php'; ?>` at the top and the `<script>const events = ...</script>` block are added during the **Final Integration** section of the tutorial (Step 9), not during the initial HTML construction. They are shown here in their final position for clarity.

### 2.1 PHP include (top of file, before DOCTYPE)
```php
<?php include 'calendar.php'; ?>
```

### 2.2 Head
```html
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar Project</title>
    <meta name="description" content="My calendar project">
    <link rel="stylesheet" href="style.css">
</head>
```

> **Note:** The Google Fonts `<link>` tag for Inter is added during the **CSS section** of the tutorial (not during HTML), but belongs in `<head>`. Add it after the `<meta>` tags:
> ```html
> <link rel="preconnect" href="https://fonts.googleapis.com">
> <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
> <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
> ```

### 2.3 Body — Header
```html
<header>
    <h1>📅 Course Calendar<br>My Calendar Project</h1>
</header>
```

### 2.4 Body — Clock
```html
<!-- Clock -->
<div class="clock-container">
    <div id="clock"></div>
</div>
```

### 2.5 Body — Calendar Section
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

> **Note:** The previous-month element is a `<div>`, the next-month element is a `<button>` — this is exactly how the instructor builds it in the transcript.

### 2.6 Body — PHP Alert Messages (after calendar div)
```php
<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
<?php endif; ?>
```

### 2.7 Body — Modal

The modal wraps the event selector, the add/edit form, the delete form, and the cancel button.

```html
<div class="modal" id="eventModal">
    <div class="modal-content">
```

#### 2.7.1 Event Selector (for multiple events on same day)
```html
        <div id="eventSelectorWrapper">
            <label for="eventSelector"><strong>Select Event</strong></label>
            <select id="eventSelector" onchange="handleEventSelection(this.value)">
                <option disabled selected>Choose event...</option>
            </select>
        </div>
```

#### 2.7.2 Add/Edit Form
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

> **Note:** The `start_time` / `end_time` inputs are added as part of the **Time Feature** section of the tutorial, but are shown here in their final position.

#### 2.7.3 Delete Form
```html
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this appointment?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="event_id" id="deleteEventID">
            <button type="submit" class="submit-btn">🗑️ Delete this event</button>
        </form>
```

#### 2.7.4 Cancel Button
```html
        <button type="button" onclick="closeModal()" class="submit-btn">✕ Cancel</button>
```

> **Note:** The transcript assigns the cancel button `class="submit-btn"` (same class as the delete button). The instructor then styles the cancel button differently using the `.modal-content button:last-child` CSS selector (see Step 3.12) to override the crimson background with gray.

#### 2.7.5 Close modal divs
```html
    </div>
</div>
```

### 2.8 Body — Script tags (before `</body>`)
```html
<script>
    const events = <?php echo json_encode($eventsFromDB, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="calendar.js"></script>
```

> **Note:** The `const events` injection script is added during the **Final Integration** section (Step 9) of the tutorial.

---

## STEP 3 — CSS Styling (`style.css`)

Create `style.css`.

> **Note:** The Google Fonts CDN link is pasted into `index.php`'s `<head>` section during this CSS step.

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

> **Note:** The transcript spoken values are garbled for several colors (e.g. "3 uh 6B8 to F6" for `--primary`, "DB B E A F E" for `--primary-light`). The values above are the correct ones visible in the instructor's screen.

### 3.2 Global Reset
```css
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
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
    .clock-container {
        font-size: 1.4rem;
        padding: 0.75rem;
    }
}
```

> **Note:** The transcript says "1.44 rim" for the mobile font-size — this is `1.4rem` (rounding artifact in speech).

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

    .day,
    .day-name {
        min-width: 140px;
        flex-shrink: 0;
        scroll-snap-align: start;
    }
}
```

### 3.9 Day Names & Day Cells
```css
.day,
.day-name {
    text-align: center;
}

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
```

> **Note:** The transcript says "duration 2 seconds" for `.day`'s transition — this is clearly a verbal slip; `0.2s` is the correct value.

```css
.day:hover {
    background: #f3f4f6;
}

.day.today {
    background: var(--primary-light);
    border-color: var(--primary-dark);
}

.date-number {
    font-weight: bold;
    margin-bottom: 5px;
}
```

### 3.10 Event Cards

> **Note:** The transcript initially codes `.event` with `padding: 3px 6px`, `border-radius: 4px`, `margin-top: 4px`, `font-size: 14px`, `transition: transform 0.2s ease`, and `scale(1.03)`. These are all updated during the **Time Feature** section. The final values are shown below.

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

.event:hover {
    transform: scale(1.02);
}

.event .course {
    font-weight: bold;
    font-size: 13px;
}

.event .instructor {
    font-size: 12px;
    opacity: 0.85;
}

.event .time {
    font-size: 12px;
    margin-top: 3px;
    color: #f3f3f3;
}

.event-meta {
    font-size: 12px;
    color: #ef;
    line-height: 1.2;
}
```

### 3.11 Alert Messages
```css
.alert {
    max-width: 600px;
    margin: 1rem auto;
    padding: 1rem;
    border-radius: 6px;
    text-align: center;
    font-weight: bold;
}

.alert-success {
    background: var(--success);
    color: var(--success-text);
}

.alert-error {
    background: var(--error);
    color: var(--error-text);
}
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
    width: 100%;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
}

.modal-content label {
    display: block;
    font-weight: bold;
    margin-top: 1rem;
    margin-bottom: 6px;
}

.modal-content input {
    width: 100%;
    padding: 10px;
    font-size: 1rem;
    border: 1px solid #ccc;
    border-radius: 5px;
}

.modal-content button {
    margin-top: 1rem;
    padding: 10px;
    width: 100%;
    border: none;
    border-radius: 6px;
    font-size: 1rem;
    cursor: pointer;
}

.modal-content button[type="submit"] {
    background-color: var(--primary);
    color: white;
}

.submit-btn {
    background-color: crimson;
    color: #fff;
}

/* Cancel button — last button in the modal gets gray override */
.modal-content button:last-child {
    background-color: #e5e7eb;
    color: #333;
}
```

> **Note:** The transcript styles the cancel button using `.modal-content button:last-child` to override the crimson `.submit-btn` color. The cancel button itself uses `class="submit-btn"` in the HTML — the `button:last-child` rule overrides it to gray.

### 3.13 Event Selector Dropdown
```css
#eventSelector {
    width: 100%;
    padding: 10px;
    font-size: 1rem;
    margin-top: 1rem;
    margin-bottom: 1rem;
    border-radius: 5px;
    border: 1px solid #ccc;
}
```

### 3.14 Day Overlay (Add/Edit hover buttons)
```css
/* Overlay buttons logic */
.day-overlay {
    position: absolute;
    top: 6px;
    right: 6px;
    display: none;
    flex-direction: column;
    gap: 4px;
    z-index: 2;
}

.day:hover .day-overlay {
    display: flex;
}

.overlay-btn {
    background: var(--primary-dark);
    color: white;
    padding: 4px 8px;
    font-size: 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
}

.overlay-btn:hover {
    background: var(--primary);
}
```

---

## STEP 4 — Database Setup

Open phpMyAdmin at `http://localhost/phpmyadmin`.

### 4.1 Create the database

```sql
CREATE DATABASE IF NOT EXISTS calendar
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
```

### 4.2 Create the initial `appointments` table (6 columns)

> **Note:** The transcript creates the table with **6 columns** initially (no time columns). The `start_time` and `end_time` columns are added via `ALTER TABLE` during the **Time Feature** step (Step 8).

```sql
USE calendar;

CREATE TABLE IF NOT EXISTS appointments (
    id              INT(11)         NOT NULL AUTO_INCREMENT,
    course_name     VARCHAR(255)    NOT NULL,
    instructor_name VARCHAR(255)    NOT NULL,
    start_date      DATE            NOT NULL,
    end_date        DATE            NOT NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

---

## STEP 5 — PHP Database Connection (`connection.php`)

Create `connection.php`:

```php
<?php
// Connect to local MySQL server using XAMPP
$connection = new mysqli('localhost', 'root', '', 'calendar');
$connection->set_charset('utf8mb4');
```

> **WampServer users:** WampServer's MariaDB defaults to port **3307**, not 3306. Use:
> ```php
> $connection = new mysqli('localhost', 'root', '', 'calendar', 3307);
> ```

---

## STEP 6 — PHP Backend Logic (`calendar.php`)

Create `calendar.php`.

### 6.1 Setup
```php
<?php
include 'connection.php';

$successMessage = '';
$errorMessage   = '';
// Initialize a new array to store the fetched events
$eventsFromDB   = [];
```

### 6.2 Handle Add Appointment

> **Note:** The transcript initially writes the INSERT with 4 columns/params (`course_name`, `instructor_name`, `start_date`, `end_date`). The `start_time` / `end_time` are added during the Time Feature step. The final version is shown below.

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

### 6.3 Handle Edit Appointment
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
             SET course_name = ?, instructor_name = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?
             WHERE id = ?"
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

### 6.4 Handle Delete Appointment
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = $_POST['event_id'] ?? null;

    if ($id) {
        $stmt = $connection->prepare("DELETE FROM appointments WHERE id = ?");
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

Create `calendar.js`.

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

    const year            = date.getFullYear();
    const month           = date.getMonth();
    const today           = new Date();
    const totalDays       = new Date(year, month + 1, 0).getDate();
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

    // Empty cells before first day
    for (let i = 0; i < firstDayOfMonth; i++) {
        calendarEl.appendChild(document.createElement('div'));
    }

    // Loop through days
    for (let day = 1; day <= totalDays; day++) {
        const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

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

        // Filter events for this day
        const eventsToday = events.filter(e => e.date === dateString);

        // Event box container
        const eventBox = document.createElement('div');
        eventBox.className = 'events';

        // Render events
        eventsToday.forEach(event => {
            const ev = document.createElement('div');
            ev.className = 'event';

            const parts = event.title.split(' - ');

            const courseEl = document.createElement('div');
            courseEl.className = 'course';
            courseEl.textContent = parts[0] || '';

            const instructorEl = document.createElement('div');
            instructorEl.className = 'instructor';
            instructorEl.textContent = '👨‍🏫 ' + (parts[1] || '');

            const timeEl = document.createElement('div');
            timeEl.className = 'time';
            timeEl.textContent = '🕐 ' + event.start_time + ' - ' + event.end_time;

            ev.appendChild(courseEl);
            ev.appendChild(instructorEl);
            ev.appendChild(timeEl);
            eventBox.appendChild(ev);
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
        cell.appendChild(eventBox);
        calendarEl.appendChild(cell);
    }
}
```

### 7.3 `openModalForAdd(dateString)`
```js
function openModalForAdd(dateString) {
    document.getElementById('formAction').value     = 'add';
    document.getElementById('eventID').value        = '';
    document.getElementById('deleteEventID').value  = '';
    document.getElementById('courseName').value     = '';
    document.getElementById('instructorName').value = '';
    document.getElementById('startDate').value      = dateString;
    document.getElementById('endDate').value        = dateString;
    document.getElementById('startTime').value      = '09:00';
    document.getElementById('endTime').value        = '10:00';

    const selector = document.getElementById('eventSelector');
    const wrapper  = document.getElementById('eventSelectorWrapper');
    if (selector && wrapper) {
        selector.innerHTML = '';
        wrapper.style.display = 'none';
    }

    modalEl.style.display = 'flex';
}
```

### 7.4 `openModalForEdit(eventsOnDate)`
```js
function openModalForEdit(eventsOnDate) {
    document.getElementById('formAction').value = 'edit';
    modalEl.style.display = 'flex';

    const selector = document.getElementById('eventSelector');
    const wrapper  = document.getElementById('eventSelectorWrapper');

    selector.innerHTML = '<option disabled selected>Choose event...</option>';

    eventsOnDate.forEach(function (e) {
        const option = document.createElement('option');
        option.value = JSON.stringify(e);
        option.textContent = e.title.split(' - ')[0] + ' (' + e.start + ' → ' + e.end + ')';
        selector.appendChild(option);
    });

    if (eventsOnDate.length > 1) {
        wrapper.style.display = 'block';
    } else {
        wrapper.style.display = 'none';
    }

    handleEventSelection(JSON.stringify(eventsOnDate[0]));
}
```

> **Note:** The transcript sets `modalEl.style.display = 'flex'` at the **start** of this function (before building the selector), then calls `handleEventSelection` at the end.

### 7.5 `handleEventSelection(eventJSON)`
```js
function handleEventSelection(eventJSON) {
    // Populate form from selected event
    const event = JSON.parse(eventJSON);

    document.getElementById('eventID').value        = event.id;
    document.getElementById('deleteEventID').value  = event.id;

    const [course, instructor] = event.title.split(' - ').map(e => e.trim());

    document.getElementById('courseName').value     = course || '';
    document.getElementById('instructorName').value = instructor || '';
    document.getElementById('startDate').value      = event.start;
    document.getElementById('endDate').value        = event.end;
    document.getElementById('startTime').value      = event.start_time;
    document.getElementById('endTime').value        = event.end_time;
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
// Month navigation
function changeMonth(offset) {
    currentDate.setMonth(currentDate.getMonth() + offset);
    renderCalendar(currentDate);
}
```

### 7.8 `updateClock()`
```js
// Live digital clock
function updateClock() {
    const now   = new Date();
    const clock = document.getElementById('clock');
    clock.textContent = [
        now.getHours().toString().padStart(2, '0'),
        now.getMinutes().toString().padStart(2, '0'),
        now.getSeconds().toString().padStart(2, '0')
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

## STEP 8 — Time Feature

The transcript adds `start_time` / `end_time` as a new feature after the initial build is complete. These are the changes made in that section:

### 8.1 HTML changes (`index.php`)
Add after the `end_date` input, inside the form:
```html
<label for="startTime">Start Time</label>
<input type="time" name="start_time" id="startTime" required>

<label for="endTime">End Time</label>
<input type="time" name="end_time" id="endTime" required>
```

### 8.2 CSS changes (`style.css`)
Update `.event` properties (from their initial values to final values):

| Property | Initial value | Final value |
|----------|--------------|-------------|
| `padding` | `3px 6px` | `6px 8px` |
| `border-radius` | `4px` | `6px` |
| `margin-top` | `4px` | `6px` |
| `font-size` | `14px` | `13px` |
| `transition` | `transform 0.2s ease` | `transform 0.15s ease` |
| `transform scale` | `1.03` | `1.02` |

Add new sub-element rules:
```css
.event .course { font-weight: bold; font-size: 13px; }
.event .instructor { font-size: 12px; opacity: 0.85; }
.event .time { font-size: 12px; margin-top: 3px; color: #f3f3f3; }
.event-meta { font-size: 12px; color: #ef; line-height: 1.2; }
```

Also add flex layout to `.event`:
```css
display: flex;
flex-direction: column;
align-items: flex-start;
box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
line-height: 1.4;
```

### 8.3 PHP changes (`calendar.php`)
Add `$startTime` and `$endTime` variables in both the add and edit handlers. Update the INSERT statement to include 6 columns (add `start_time, end_time`) with 6 `?` placeholders and `'ssssss'` bind_param. Update the UPDATE statement similarly with `'ssssssi'`. Add `start_time` and `end_time` to the `$eventsFromDB[]` array.

### 8.4 Database changes
Alter the `appointments` table to add two new columns after `end_date`:
```sql
ALTER TABLE appointments ADD COLUMN start_time TIME NOT NULL AFTER end_date;
ALTER TABLE appointments ADD COLUMN end_time   TIME NOT NULL AFTER start_time;
```

---

## STEP 9 — Final Integration

### 9.1 Add PHP include to `index.php`
At the very top of `index.php`, before `<!DOCTYPE html>`:
```php
<?php include 'calendar.php'; ?>
```

### 9.2 Add events JS injection to `index.php`
Just before `<script src="calendar.js"></script>`:
```html
<script>
    const events = <?php echo json_encode($eventsFromDB, JSON_UNESCAPED_UNICODE); ?>;
</script>
```

### 9.3 Reload the page
Navigate to `http://localhost/calendar-project/` — the calendar should now display with all days rendered.

---

## STEP 10 — Verification Checklist

### Files
- [ ] `index.php` — starts with `<?php include 'calendar.php'; ?>`, ends with events script + `calendar.js`
- [ ] `calendar.php` — handles add/edit/delete, fetches and spreads events
- [ ] `connection.php` — connects to `calendar` database
- [ ] `style.css` — all styles including time feature updates
- [ ] `calendar.js` — all functions including `eventBox` container

### Database
- [ ] Database `calendar` exists
- [ ] Table `appointments` has columns: `id`, `course_name`, `instructor_name`, `start_date`, `end_date`, `start_time`, `end_time`, `created_at`

### Functional Tests
| Test | Expected Result |
|------|----------------|
| Load page | Calendar grid renders with day name headers + day cells |
| Current day | Highlighted with blue/primary-light background |
| Clock | Displays HH:MM:SS, increments every second |
| Navigate months | Prev (`‹`) / Next (`›`) arrows change the month/year heading |
| Reload | Returns to current month and year |
| Click day → Add | Modal opens, start/end date pre-filled with clicked date |
| Submit add form | Redirect with success message; event spans across date range |
| Hover day with event | Overlay shows "Add" + "Edit" buttons |
| Click Edit (1 event) | Modal opens with all event fields pre-filled |
| Click Edit (2+ events) | Dropdown selector appears to choose which event to edit |
| Select from dropdown | Form fields update to show selected event's data |
| Submit edit form | Redirect with success message; event updated |
| Click Delete | Browser confirmation dialog appears |
| Confirm delete | Redirect with success message; event removed from calendar |
| Submit form with empty fields | Redirect with error message shown |
| Check DB | `appointments` table rows contain correct `start_time` and `end_time` values |
