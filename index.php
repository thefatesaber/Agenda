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
</head>
<body>

    <header>
        <h1>&#128197; Course Calendar<br>My Calendar Project</h1>
    </header>

    <!-- Clock -->
    <div class="clock-container">
        <div id="clock"></div>
    </div>

    <div class="calendar">
        <div class="nav-btn-container">
            <div class="nav-btn" onclick="changeMonth(-1)">&#8249;</div>
            <h2 id="monthYear" style="margin:0"></h2>
            <button class="nav-btn" onclick="changeMonth(1)">&#8250;</button>
        </div>

        <div class="calendar-grid" id="calendar"></div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <!-- Modal for Add/Edit/Delete Appointment -->
    <div class="modal" id="eventModal">
        <div class="modal-content">

            <div id="eventSelectorWrapper" style="display:none;">
                <label for="eventSelector"><strong>Select Event</strong></label>
                <select id="eventSelector" onchange="handleEventSelection(this.value)">
                    <option disabled selected>Choose event...</option>
                </select>
            </div>

            <!-- Main Form -->
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

                <button type="submit">&#128190; Save</button>
            </form>

            <!-- Delete Form -->
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this appointment?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="event_id" id="deleteEventID">
                <button type="submit" class="submit-btn">&#128465; Delete</button>
            </form>

            <button type="button" onclick="closeModal()" class="cancel-btn">&#10005; Cancel</button>

        </div>
    </div>

    <script>
        const events = <?php echo json_encode($eventsFromDB, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="calendar.js"></script>
</body>
</html>
