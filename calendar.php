<?php
require_once __DIR__ . '/config.php';
include __DIR__ . '/connection.php';

$successMessage = '';
$errorMessage   = '';
$eventsFromDB   = [];
$showUndo       = false;

// ── Auto-migrate: appointments table ─────────────────────────────────────────
$check = $connection->query("SHOW COLUMNS FROM appointments LIKE 'recurrence'");
if ($check && $check->num_rows === 0) {
    $connection->query("ALTER TABLE appointments ADD COLUMN recurrence VARCHAR(10) NOT NULL DEFAULT 'none'");
    $connection->query("ALTER TABLE appointments ADD COLUMN recurrence_end DATE NULL");
}

$newCols = [
    'color'       => "ALTER TABLE appointments ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#6B82F6'",
    'category'    => "ALTER TABLE appointments ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT ''",
    'notes'       => "ALTER TABLE appointments ADD COLUMN notes TEXT NULL",
    'deleted_at'  => "ALTER TABLE appointments ADD COLUMN deleted_at DATETIME NULL",
    'user_id'     => "ALTER TABLE appointments ADD COLUMN user_id INT NULL",
    'exclusions'  => "ALTER TABLE appointments ADD COLUMN exclusions TEXT NULL",
    'attachment'  => "ALTER TABLE appointments ADD COLUMN attachment VARCHAR(255) NULL",
    'event_url'   => "ALTER TABLE appointments ADD COLUMN event_url VARCHAR(500) NULL",
    'priority'    => "ALTER TABLE appointments ADD COLUMN priority TINYINT NOT NULL DEFAULT 1",
    // New columns (batch 21-100)
    'subtasks'      => "ALTER TABLE appointments ADD COLUMN subtasks TEXT NULL",
    'attendees'     => "ALTER TABLE appointments ADD COLUMN attendees TEXT NULL",
    'location'      => "ALTER TABLE appointments ADD COLUMN location VARCHAR(255) NULL",
    'rsvp_status'   => "ALTER TABLE appointments ADD COLUMN rsvp_status VARCHAR(50) NULL DEFAULT 'none'",
    'visibility'    => "ALTER TABLE appointments ADD COLUMN visibility VARCHAR(10) NOT NULL DEFAULT 'public'",
    'status'        => "ALTER TABLE appointments ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'confirmed'",
    'capacity'      => "ALTER TABLE appointments ADD COLUMN capacity INT NULL",
    'tags'          => "ALTER TABLE appointments ADD COLUMN tags VARCHAR(500) NULL",
    'related_ids'   => "ALTER TABLE appointments ADD COLUMN related_ids TEXT NULL",
    'recurrence_count' => "ALTER TABLE appointments ADD COLUMN recurrence_count INT NULL",
    'version'       => "ALTER TABLE appointments ADD COLUMN version INT NOT NULL DEFAULT 1",
    'zoom_url'      => "ALTER TABLE appointments ADD COLUMN zoom_url VARCHAR(500) NULL",
    'reminders'     => "ALTER TABLE appointments ADD COLUMN reminders TEXT NULL",
    'actual_start'  => "ALTER TABLE appointments ADD COLUMN actual_start TIME NULL",
    'actual_end'    => "ALTER TABLE appointments ADD COLUMN actual_end TIME NULL",
    'calendar_id'   => "ALTER TABLE appointments ADD COLUMN calendar_id INT NULL",
    'deadline'      => "ALTER TABLE appointments ADD COLUMN deadline DATETIME NULL",
];
foreach ($newCols as $col => $sql) {
    $chk = $connection->query("SHOW COLUMNS FROM appointments LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $connection->query($sql);
    }
}

// ── Create users table ────────────────────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$userCols = [
    'remember_token' => "ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL",
    'display_name'   => "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL",
    'avatar'         => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL",
    'timezone'       => "ALTER TABLE users ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC'",
    'default_calendar' => "ALTER TABLE users ADD COLUMN default_calendar INT NULL",
];
foreach ($userCols as $col => $sql) {
    $chk = $connection->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($chk && $chk->num_rows === 0) $connection->query($sql);
}

// ── Create calendars table (#51) ──────────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS calendars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6B82F6',
    visible TINYINT NOT NULL DEFAULT 1,
    description TEXT NULL,
    is_default TINYINT NOT NULL DEFAULT 0,
    group_name VARCHAR(100) NULL,
    archived TINYINT NOT NULL DEFAULT 0,
    share_token VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── Create event_attendees table (#61) ────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS event_attendees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    permission VARCHAR(10) NOT NULL DEFAULT 'view',
    rsvp VARCHAR(20) NOT NULL DEFAULT 'pending',
    notified TINYINT NOT NULL DEFAULT 0,
    INDEX(event_id), INDEX(user_id)
)");

// ── Create event_comments table (#63) ─────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS event_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(event_id)
)");

// ── Create activity_log table (#64) ───────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    event_id INT NULL,
    action VARCHAR(50) NOT NULL,
    detail TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(event_id)
)");

// ── Create event_history table (#35) ──────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS event_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NULL,
    snapshot TEXT NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(event_id)
)");

// ── Create api_keys table (#70) ───────────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME NULL
)");

// ── Create filter_presets table (#45) ─────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS filter_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    filters TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── Create webhooks table (#69) ───────────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    url VARCHAR(500) NOT NULL,
    events VARCHAR(200) NOT NULL DEFAULT 'create,edit,delete',
    active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── Create notifications table (#93) ──────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    event_id INT NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id)
)");

// ── Create settings table (for SMTP, Twilio, etc.) ────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    UNIQUE KEY user_key (user_id, setting_key)
)");

// ── Auto-migrate: parent_id for sub-events (#61) ──────────────────────────────
$chkParent = $connection->query("SHOW COLUMNS FROM appointments LIKE 'parent_id'");
if ($chkParent && $chkParent->num_rows === 0) {
    $connection->query("ALTER TABLE appointments ADD COLUMN parent_id INT NULL DEFAULT NULL");
}

// ── Auto-migrate: group_name for calendar groups (#55) ────────────────────────
$chkGroup = $connection->query("SHOW COLUMNS FROM calendars LIKE 'group_name'");
if ($chkGroup && $chkGroup->num_rows === 0) {
    $connection->query("ALTER TABLE calendars ADD COLUMN group_name VARCHAR(100) DEFAULT NULL");
}

// ── Create event_permissions table (#62) ──────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS `event_permissions` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    permission ENUM('view','edit') NOT NULL DEFAULT 'view',
    granted_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(event_id), INDEX(user_id)
)");

// ── Create calendar_shares table (#66) ────────────────────────────────────────
$connection->query("CREATE TABLE IF NOT EXISTS `calendar_shares` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT NOT NULL,
    user_id INT NOT NULL,
    permission ENUM('view','edit') NOT NULL DEFAULT 'view',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(calendar_id), INDEX(user_id)
)");

// ── Auto-migrate: is_read column in notifications (#66/#67) ──────────────────
$chkIsRead = $connection->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
if ($chkIsRead && $chkIsRead->num_rows === 0) {
    $connection->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT NOT NULL DEFAULT 0");
}

// ── Auto-migrate: email column in users (#66/#67) ─────────────────────────────
$chkEmail = $connection->query("SHOW COLUMNS FROM users LIKE 'email'");
if ($chkEmail && $chkEmail->num_rows === 0) {
    $connection->query("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL");
}

// ── Ensure uploads directory ──────────────────────────────────────────────────
if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
if (!is_dir(__DIR__ . '/uploads/avatars')) mkdir(__DIR__ . '/uploads/avatars', 0755, true);

// ── Auth ──────────────────────────────────────────────────────────────────────
include __DIR__ . '/auth.php';
$currentUserId = $_SESSION['user_id'] ?? null;

// ── Helper: log activity ──────────────────────────────────────────────────────
function logActivity($conn, $userId, $eventId, $action, $detail = null) {
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, event_id, action, detail) VALUES (?,?,?,?)");
    if ($stmt) { $stmt->bind_param('iiss', $userId, $eventId, $action, $detail); $stmt->execute(); $stmt->close(); }
}

// ── Helper: save event history ────────────────────────────────────────────────
function saveEventHistory($conn, $eventId, $userId) {
    $r = $conn->query("SELECT * FROM appointments WHERE id=$eventId LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $snap = json_encode($row, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("INSERT INTO event_history (event_id, user_id, snapshot) VALUES (?,?,?)");
        if ($stmt) { $stmt->bind_param('iis', $eventId, $userId, $snap); $stmt->execute(); $stmt->close(); }
    }
}

// ── Helper: fire webhooks ─────────────────────────────────────────────────────
function fireWebhooks($conn, $action, $eventData) {
    $res = $conn->query("SELECT url FROM webhooks WHERE active=1 AND FIND_IN_SET('$action', events)");
    if (!$res) return;
    while ($row = $res->fetch_assoc()) {
        $payload = json_encode(['action' => $action, 'event' => $eventData, 'timestamp' => time()]);
        @file_get_contents($row['url'], false, stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n", 'content' => $payload, 'timeout' => 2],
        ]));
    }
}

// ── GET: export_csv ───────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="events.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','course_name','instructor_name','start_date','end_date','start_time','end_time','recurrence','recurrence_end','color','category','notes','event_url','priority','location','tags','status','visibility']);
    $q = "SELECT id, course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, location, tags, status, visibility FROM appointments WHERE deleted_at IS NULL";
    if (REQUIRE_AUTH && $currentUserId) { $uid = (int)$currentUserId; $q .= " AND (user_id = $uid OR user_id IS NULL)"; }
    $res = $connection->query($q);
    if ($res) { while ($row = $res->fetch_assoc()) { fputcsv($out, $row); } }
    fclose($out);
    exit;
}

// ── GET: export_ical ──────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_ical') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="events.ics"');
    $q = "SELECT * FROM appointments WHERE deleted_at IS NULL";
    if (REQUIRE_AUTH && $currentUserId) { $uid = (int)$currentUserId; $q .= " AND (user_id = $uid OR user_id IS NULL)"; }
    $res = $connection->query($q);
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Calendar App//EN\r\n";
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dtstart = str_replace('-', '', $row['start_date']);
            $dtend   = str_replace('-', '', $row['end_date']);
            if ($row['start_time']) {
                $dtstart .= 'T' . str_replace(':', '', substr($row['start_time'], 0, 5)) . '00';
                $dtend   .= 'T' . str_replace(':', '', substr($row['end_time'] ?: $row['start_time'], 0, 5)) . '00';
            }
            $uidv    = 'event-' . $row['id'] . '@calendar';
            $summary = addcslashes($row['course_name'] . ' - ' . $row['instructor_name'], ',;\\');
            $desc    = addcslashes($row['notes'] ?? '', ',;\\');
            $url     = $row['event_url'] ?? '';
            $cat     = $row['category'] ?? '';
            $loc     = addcslashes($row['location'] ?? '', ',;\\');
            echo "BEGIN:VEVENT\r\nUID:$uidv\r\nDTSTART:$dtstart\r\nDTEND:$dtend\r\nSUMMARY:$summary\r\n";
            if ($desc) echo "DESCRIPTION:$desc\r\n";
            if ($url)  echo "URL:$url\r\n";
            if ($cat)  echo "CATEGORIES:$cat\r\n";
            if ($loc)  echo "LOCATION:$loc\r\n";
            echo "STATUS:" . strtoupper($row['status'] ?? 'CONFIRMED') . "\r\n";
            echo "END:VEVENT\r\n";
        }
    }
    echo "END:VCALENDAR\r\n";
    exit;
}

// ── GET: export_sql ───────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_sql') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup.sql"');
    $createSQL = "-- Calendar App Backup\nCREATE TABLE IF NOT EXISTS `appointments` (\n"
        . "  `id` int NOT NULL AUTO_INCREMENT,\n  `course_name` varchar(255) NOT NULL,\n"
        . "  `instructor_name` varchar(255) NOT NULL DEFAULT '',\n  `start_date` date NOT NULL,\n"
        . "  `end_date` date NOT NULL,\n  `start_time` time DEFAULT NULL,\n  `end_time` time DEFAULT NULL,\n"
        . "  `recurrence` varchar(10) NOT NULL DEFAULT 'none',\n  `recurrence_end` date DEFAULT NULL,\n"
        . "  `color` varchar(7) NOT NULL DEFAULT '#6B82F6',\n  `category` varchar(50) NOT NULL DEFAULT '',\n"
        . "  `notes` text,\n  `deleted_at` datetime DEFAULT NULL,\n  `user_id` int DEFAULT NULL,\n"
        . "  `exclusions` text,\n  `attachment` varchar(255) DEFAULT NULL,\n  `event_url` varchar(500) DEFAULT NULL,\n"
        . "  `priority` tinyint NOT NULL DEFAULT 1,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
    echo $createSQL;
    $q = "SELECT * FROM appointments WHERE deleted_at IS NULL";
    if (REQUIRE_AUTH && $currentUserId) { $uid = (int)$currentUserId; $q .= " AND (user_id = $uid OR user_id IS NULL)"; }
    $res = $connection->query($q);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $vals = [];
            foreach ($row as $v) { $vals[] = ($v === null) ? 'NULL' : "'" . $connection->real_escape_string($v) . "'"; }
            echo "INSERT INTO `appointments` VALUES (" . implode(', ', $vals) . ");\n";
        }
    }
    exit;
}

// ── POST: restore_sql ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_sql') {
    if (DEV_MODE && isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['sql_file']['tmp_name']);
        $statements = array_filter(array_map('trim', explode(';', $content)));
        foreach ($statements as $stmt) { if ($stmt) $connection->query($stmt); }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=8'); exit;
}

// ── POST: import_csv / ical ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $imported = 0;
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['csv_file']['tmp_name']);
        if (strpos(ltrim($content), 'BEGIN:VCALENDAR') === 0) {
            preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $content, $matches);
            foreach ($matches[1] as $block) {
                $get = function($prop) use ($block) {
                    if (preg_match('/^' . preg_quote($prop, '/') . '[;:](.*)/m', $block, $m)) return trim($m[1]);
                    return '';
                };
                $dtstart = $get('DTSTART'); $dtend = $get('DTEND');
                $summary = $get('SUMMARY'); $desc = $get('DESCRIPTION');
                $cat = $get('CATEGORIES'); $url = $get('URL'); $loc = $get('LOCATION');
                $parseIcalDate = function($dt) {
                    $dt = preg_replace('/[TZ]/', '', $dt);
                    if (strlen($dt) >= 8) {
                        $date = substr($dt,0,4).'-'.substr($dt,4,2).'-'.substr($dt,6,2);
                        $time = strlen($dt) >= 14 ? substr($dt,8,2).':'.substr($dt,10,2) : null;
                        return [$date, $time];
                    }
                    return [null, null];
                };
                [$startDate, $startTime] = $parseIcalDate($dtstart);
                [$endDate, $endTime]     = $parseIcalDate($dtend);
                if (!$startDate) continue;
                if (!$endDate) $endDate = $startDate;
                $parts = explode(' - ', $summary, 2);
                $course = trim($parts[0] ?? $summary); $instr = trim($parts[1] ?? '');
                $none = 'none'; $prio = 1;
                $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, color, category, notes, event_url, priority, user_id, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $defColor = '#6B82F6';
                $stmt->bind_param('sssssssssssiis', $course, $instr, $startDate, $endDate, $startTime, $endTime, $none, $defColor, $cat, $desc, $url, $prio, $currentUserId, $loc);
                if ($stmt->execute()) $imported++;
                $stmt->close();
            }
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                $headerRow = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 8) continue;
                    $course = trim($row[1] ?? ''); $instr = trim($row[2] ?? '');
                    $start = trim($row[3] ?? ''); $end = trim($row[4] ?? '');
                    $stime = trim($row[5] ?? ''); $etime = trim($row[6] ?? '');
                    $recur = trim($row[7] ?? 'none'); $recurEnd = (trim($row[8] ?? '') !== '') ? trim($row[8]) : null;
                    $color = trim($row[9] ?? '#6B82F6') ?: '#6B82F6';
                    $cat = trim($row[10] ?? ''); $notes = trim($row[11] ?? '');
                    $evtUrl = trim($row[12] ?? ''); $prio = (int)(trim($row[13] ?? '1') ?: 1);
                    $loc = trim($row[14] ?? ''); $tags = trim($row[15] ?? '');
                    if (!$course || !$start || !$end) continue;
                    $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, user_id, location, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('ssssssssssssiiss', $course, $instr, $start, $end, $stime, $etime, $recur, $recurEnd, $color, $cat, $notes, $evtUrl, $prio, $currentUserId, $loc, $tags);
                    if ($stmt->execute()) $imported++;
                    $stmt->close();
                }
                fclose($handle);
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=5&n=' . $imported); exit;
}

// ── POST: add ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $course        = trim($_POST['course_name'] ?? '');
    $instructor    = trim($_POST['instructor_name'] ?? '');
    $start         = $_POST['start_date'] ?? '';
    $end           = $_POST['end_date'] ?? '';
    $startTime     = $_POST['start_time'] ?? '';
    $endTime       = $_POST['end_time'] ?? '';
    $recurrence    = $_POST['recurrence'] ?? 'none';
    $recurrenceEnd = ($recurrence !== 'none' && !empty($_POST['recurrence_end'])) ? $_POST['recurrence_end'] : null;
    $recurrenceCount = ($recurrence !== 'none' && !empty($_POST['recurrence_count'])) ? (int)$_POST['recurrence_count'] : null;
    $color         = $_POST['color'] ?? '#6B82F6';
    $category      = trim($_POST['category'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $eventUrl      = trim($_POST['event_url'] ?? '');
    $priority      = (int)($_POST['priority'] ?? 1);
    $location      = trim($_POST['location'] ?? '');
    $tags          = trim($_POST['tags'] ?? '');
    $status        = $_POST['status'] ?? 'confirmed';
    $visibility    = $_POST['visibility'] ?? 'public';
    $capacity      = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null;
    $zoomUrl       = trim($_POST['zoom_url'] ?? '');
    $attendees     = trim($_POST['attendees'] ?? '');
    $reminders     = trim($_POST['reminders'] ?? '');
    $deadline      = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $calendarId    = !empty($_POST['calendar_id']) ? (int)$_POST['calendar_id'] : null;

    $attachment = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['attachment']['name']);
        $newName  = uniqid() . '_' . $origName;
        $dest     = __DIR__ . '/uploads/' . $newName;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) $attachment = 'uploads/' . $newName;
    }

    if ($course && $instructor && $start && $end) {
        $conflictId = null;
        if ($startTime && $endTime) {
            $cq = $connection->prepare("SELECT id FROM appointments WHERE deleted_at IS NULL AND start_date <= ? AND end_date >= ? AND start_time < ? AND end_time > ? LIMIT 1");
            $cq->bind_param('ssss', $end, $start, $endTime, $startTime);
            $cq->execute(); $cq->bind_result($conflictId); $cq->fetch(); $cq->close();
        }

        // Build INSERT with explicit types
        // course(s) instructor(s) start(s) end(s) startTime(s) endTime(s) recurrence(s) recurrenceEnd(s) recurrenceCount(i)
        // color(s) category(s) notes(s) eventUrl(s) priority(i) attachment(s) currentUserId(i)
        // location(s) tags(s) status(s) visibility(s) capacity(i) zoomUrl(s) attendees(s) reminders(s) deadline(s) calendarId(i)
        $insertParams = [
            $course, $instructor, $start, $end, $startTime, $endTime,
            $recurrence, $recurrenceEnd, $recurrenceCount, $color, $category,
            $notes, $eventUrl, $priority, $attachment, $currentUserId,
            $location, $tags, $status, $visibility, $capacity,
            $zoomUrl, $attendees, $reminders, $deadline, $calendarId
        ];
        $typeStr = 'ssssssssi' . 'ssssi' . 'si' . 'ssssi' . 'ssssi'; // 26 total
        $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, recurrence_count, color, category, notes, event_url, priority, attachment, user_id, location, tags, status, visibility, capacity, zoom_url, attendees, reminders, deadline, calendar_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param($typeStr, ...$insertParams);
        $stmt->execute();
        $newId = $connection->insert_id;
        $stmt->close();

        // Save history entry
        if ($newId) saveEventHistory($connection, $newId, $currentUserId);
        // Log activity
        logActivity($connection, $currentUserId, $newId, 'create', $course);
        // Fire webhooks
        fireWebhooks($connection, 'create', ['id' => $newId, 'title' => $course]);

        if ($conflictId) { $_SESSION['conflict_id'] = (int)$conflictId; header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1&warning=conflict'); }
        else header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1'); exit;
    }
}

// ── POST: edit ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id            = $_POST['event_id'] ?? null;
    $course        = trim($_POST['course_name'] ?? '');
    $instructor    = trim($_POST['instructor_name'] ?? '');
    $start         = $_POST['start_date'] ?? '';
    $end           = $_POST['end_date'] ?? '';
    $startTime     = $_POST['start_time'] ?? '';
    $endTime       = $_POST['end_time'] ?? '';
    $recurrence    = $_POST['recurrence'] ?? 'none';
    $recurrenceEnd = ($recurrence !== 'none' && !empty($_POST['recurrence_end'])) ? $_POST['recurrence_end'] : null;
    $recurrenceCount = ($recurrence !== 'none' && !empty($_POST['recurrence_count'])) ? (int)$_POST['recurrence_count'] : null;
    $color         = $_POST['color'] ?? '#6B82F6';
    $category      = trim($_POST['category'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $eventUrl      = trim($_POST['event_url'] ?? '');
    $priority      = (int)($_POST['priority'] ?? 1);
    $location      = trim($_POST['location'] ?? '');
    $tags          = trim($_POST['tags'] ?? '');
    $status        = $_POST['status'] ?? 'confirmed';
    $visibility    = $_POST['visibility'] ?? 'public';
    $capacity      = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null;
    $zoomUrl       = trim($_POST['zoom_url'] ?? '');
    $attendees     = trim($_POST['attendees'] ?? '');
    $reminders     = trim($_POST['reminders'] ?? '');
    $deadline      = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $calendarId    = !empty($_POST['calendar_id']) ? (int)$_POST['calendar_id'] : null;
    $subtasks      = trim($_POST['subtasks'] ?? '');

    if ($id && $course && $instructor && $start && $end) {
        // ── Optimistic locking: check version (#59) ────────────────────────────
        $postedVersion = isset($_POST['version']) ? (int)$_POST['version'] : null;
        if ($postedVersion !== null) {
            $vRes = $connection->prepare("SELECT version, attendees FROM appointments WHERE id=? LIMIT 1");
            $vRes->bind_param('i', $id); $vRes->execute();
            $vRes->bind_result($dbVersion, $dbAttendees); $vRes->fetch(); $vRes->close();
            if ($dbVersion !== null && $postedVersion !== $dbVersion) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=-1&conflict=1'); exit;
            }
        } else {
            // Fetch attendees for notification check even without version
            $vRes2 = $connection->query("SELECT attendees FROM appointments WHERE id=" . (int)$id . " LIMIT 1");
            $dbAttendees = $vRes2 ? $vRes2->fetch_assoc()['attendees'] ?? '' : '';
        }

        // Save history before edit (#35)
        saveEventHistory($connection, $id, $currentUserId);

        $attachSql = ''; $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $origName = basename($_FILES['attachment']['name']);
            $newName  = uniqid() . '_' . $origName;
            $dest     = __DIR__ . '/uploads/' . $newName;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) { $attachment = 'uploads/' . $newName; $attachSql = ', attachment=?'; }
        }

        $conflictId = null;
        if ($startTime && $endTime) {
            $cq = $connection->prepare("SELECT id FROM appointments WHERE deleted_at IS NULL AND id != ? AND start_date <= ? AND end_date >= ? AND start_time < ? AND end_time > ? LIMIT 1");
            $cq->bind_param('issss', $id, $end, $start, $endTime, $startTime);
            $cq->execute(); $cq->bind_result($conflictId); $cq->fetch(); $cq->close();
        }

        // Increment version
        $connection->query("UPDATE appointments SET version = version + 1 WHERE id=" . (int)$id);

        if ($attachSql) {
            // 27 params: course..recurrenceCount(ssssssssi) + color..priority(ssssi) + location..capacity(ssssi) + zoomUrl..calendarId(ssssi) + subtasks+attachment+id(ssi)
            $editParams = [$course, $instructor, $start, $end, $startTime, $endTime,
                $recurrence, $recurrenceEnd, $recurrenceCount, $color, $category,
                $notes, $eventUrl, $priority, $location, $tags, $status, $visibility, $capacity,
                $zoomUrl, $attendees, $reminders, $deadline, $calendarId, $subtasks, $attachment, $id];
            $editTypes = 'ssssssssissssissssissssissi'; // 27 chars
            $stmt = $connection->prepare("UPDATE appointments SET course_name=?, instructor_name=?, start_date=?, end_date=?, start_time=?, end_time=?, recurrence=?, recurrence_end=?, recurrence_count=?, color=?, category=?, notes=?, event_url=?, priority=?, location=?, tags=?, status=?, visibility=?, capacity=?, zoom_url=?, attendees=?, reminders=?, deadline=?, calendar_id=?, subtasks=?, attachment=? WHERE id=?");
            $stmt->bind_param($editTypes, ...$editParams);
        } else {
            // 26 params: course..recurrenceCount(ssssssssi) + color..priority(ssssi) + location..capacity(ssssi) + zoomUrl..calendarId(ssssi) + subtasks+id(si)
            $editParams = [$course, $instructor, $start, $end, $startTime, $endTime,
                $recurrence, $recurrenceEnd, $recurrenceCount, $color, $category,
                $notes, $eventUrl, $priority, $location, $tags, $status, $visibility, $capacity,
                $zoomUrl, $attendees, $reminders, $deadline, $calendarId, $subtasks, $id];
            $editTypes = 'ssssssssissssissssissssisi'; // 26 chars (note: calendarId=i then subtasks=s then id=i)
            $stmt = $connection->prepare("UPDATE appointments SET course_name=?, instructor_name=?, start_date=?, end_date=?, start_time=?, end_time=?, recurrence=?, recurrence_end=?, recurrence_count=?, color=?, category=?, notes=?, event_url=?, priority=?, location=?, tags=?, status=?, visibility=?, capacity=?, zoom_url=?, attendees=?, reminders=?, deadline=?, calendar_id=?, subtasks=? WHERE id=?");
            $stmt->bind_param($editTypes, ...$editParams);
        }
        $stmt->execute(); $stmt->close();

        logActivity($connection, $currentUserId, $id, 'edit', $course);
        fireWebhooks($connection, 'edit', ['id' => $id, 'title' => $course]);

        // ── Attendee change notifications (#66/#67) ────────────────────────────
        $newAttendees = trim($_POST['attendees'] ?? '');
        $oldList = array_filter(array_map('trim', explode(',', $dbAttendees ?? '')));
        $newList = array_filter(array_map('trim', explode(',', $newAttendees)));
        $addedAttendees = array_diff($newList, $oldList);
        foreach ($addedAttendees as $aEmail) {
            if (!$aEmail) continue;
            // Find user by username or email
            $safeEmail = $connection->real_escape_string($aEmail);
            $uNotifRes = $connection->query("SELECT id FROM users WHERE username='$safeEmail' OR email='$safeEmail' LIMIT 1");
            if ($uNotifRes && ($uNotifRow = $uNotifRes->fetch_assoc())) {
                $notifUserId = (int)$uNotifRow['id'];
                $notifMsg = "You were added as an attendee to: $course";
                $stmtN = $connection->prepare("INSERT INTO notifications (user_id, event_id, message) VALUES (?,?,?)");
                if ($stmtN) { $stmtN->bind_param('iis', $notifUserId, $id, $notifMsg); $stmtN->execute(); $stmtN->close(); }
            }
        }

        if ($conflictId) { $_SESSION['conflict_id'] = (int)$conflictId; header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2&warning=conflict'); }
        else header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2');
        exit;
    } else { header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2'); exit; }
}

// ── POST: edit_occurrence ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_occurrence') {
    $id = $_POST['event_id'] ?? null; $occurrenceDate = $_POST['occurrence_date'] ?? null;
    $course = trim($_POST['course_name'] ?? ''); $instructor = trim($_POST['instructor_name'] ?? '');
    $start = $_POST['start_date'] ?? ''; $end = $_POST['end_date'] ?? '';
    $startTime = $_POST['start_time'] ?? ''; $endTime = $_POST['end_time'] ?? '';
    $color = $_POST['color'] ?? '#6B82F6'; $category = trim($_POST['category'] ?? '');
    $notes = trim($_POST['notes'] ?? ''); $eventUrl = trim($_POST['event_url'] ?? '');
    $priority = (int)($_POST['priority'] ?? 1); $location = trim($_POST['location'] ?? '');

    if ($id && $occurrenceDate && $course && $start && $end) {
        $resExcl = $connection->prepare("SELECT exclusions FROM appointments WHERE id=?");
        $resExcl->bind_param('i', $id); $resExcl->execute(); $resExcl->bind_result($existingExcl); $resExcl->fetch(); $resExcl->close();
        $exclList = array_filter(array_map('trim', explode(',', $existingExcl ?? '')));
        if (!in_array($occurrenceDate, $exclList)) $exclList[] = $occurrenceDate;
        $newExcl = implode(',', $exclList);
        $stmtUpd = $connection->prepare("UPDATE appointments SET exclusions=? WHERE id=?");
        $stmtUpd->bind_param('si', $newExcl, $id); $stmtUpd->execute(); $stmtUpd->close();
        $none = 'none'; $prio = $priority;
        $stmtIns = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, color, category, notes, event_url, priority, user_id, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->bind_param('sssssssssssiis', $course, $instructor, $start, $end, $startTime, $endTime, $none, $color, $category, $notes, $eventUrl, $prio, $currentUserId, $location);
        $stmtIns->execute(); $stmtIns->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
    } else { header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2'); exit; }
}

// ── POST: edit_future (edit this and all future) (#21) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_future') {
    $id = $_POST['event_id'] ?? null; $occurrenceDate = $_POST['occurrence_date'] ?? null;
    $course = trim($_POST['course_name'] ?? ''); $instructor = trim($_POST['instructor_name'] ?? '');
    $start = $_POST['start_date'] ?? ''; $end = $_POST['end_date'] ?? '';
    $startTime = $_POST['start_time'] ?? ''; $endTime = $_POST['end_time'] ?? '';
    $recurrence = $_POST['recurrence'] ?? 'none'; $recurrenceEnd = !empty($_POST['recurrence_end']) ? $_POST['recurrence_end'] : null;
    $color = $_POST['color'] ?? '#6B82F6'; $category = trim($_POST['category'] ?? '');
    $notes = trim($_POST['notes'] ?? ''); $eventUrl = trim($_POST['event_url'] ?? '');
    $priority = (int)($_POST['priority'] ?? 1); $location = trim($_POST['location'] ?? '');

    if ($id && $occurrenceDate && $course && $start && $end) {
        // Set recurrence_end of original to day before occurrenceDate
        $oneDayBefore = date('Y-m-d', strtotime($occurrenceDate . ' -1 day'));
        $stmtUpd = $connection->prepare("UPDATE appointments SET recurrence_end=? WHERE id=?");
        $stmtUpd->bind_param('si', $oneDayBefore, $id); $stmtUpd->execute(); $stmtUpd->close();
        // Create new recurring event from this point
        $stmtIns = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, user_id, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->bind_param('ssssssssssssiis', $course, $instructor, $start, $end, $startTime, $endTime, $recurrence, $recurrenceEnd, $color, $category, $notes, $eventUrl, $priority, $currentUserId, $location);
        $stmtIns->execute(); $stmtIns->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
    } else { header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2'); exit; }
}

// ── POST: delete (soft-delete) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = $_POST['event_id'] ?? null;
    if ($id) {
        $now = date('Y-m-d H:i:s');
        $stmt = $connection->prepare("UPDATE appointments SET deleted_at=? WHERE id=?");
        $stmt->bind_param('si', $now, $id); $stmt->execute(); $stmt->close();
        $_SESSION['undo_id'] = (int)$id;
        logActivity($connection, $currentUserId, $id, 'delete');
        fireWebhooks($connection, 'delete', ['id' => $id]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=3'); exit;
    }
}

// ── POST: undo_delete ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo_delete') {
    $undoId = $_SESSION['undo_id'] ?? null;
    if ($undoId) {
        $stmt = $connection->prepare("UPDATE appointments SET deleted_at=NULL WHERE id=?");
        $stmt->bind_param('i', $undoId); $stmt->execute(); $stmt->close();
        unset($_SESSION['undo_id']);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=6'); exit;
}

// ── POST: skip_occurrence (#32) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'skip_occurrence') {
    $id = $_POST['event_id'] ?? null; $skipDate = trim($_POST['skip_date'] ?? '');
    if ($id && $skipDate) {
        $resExcl = $connection->prepare("SELECT exclusions FROM appointments WHERE id=?");
        $resExcl->bind_param('i', $id); $resExcl->execute(); $resExcl->bind_result($existingExcl); $resExcl->fetch(); $resExcl->close();
        $exclList = array_filter(array_map('trim', explode(',', $existingExcl ?? '')));
        if (!in_array($skipDate, $exclList)) $exclList[] = $skipDate;
        $newExcl = implode(',', $exclList);
        $stmtUpd = $connection->prepare("UPDATE appointments SET exclusions=? WHERE id=?");
        $stmtUpd->bind_param('si', $newExcl, $id); $stmtUpd->execute(); $stmtUpd->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
}

// ── POST: duplicate ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'duplicate') {
    $id = $_POST['event_id'] ?? null;
    $offsetDays = (int)($_POST['offset_days'] ?? 0);
    if ($id) {
        if ($offsetDays !== 0) {
            $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, user_id, location, tags, status, visibility) SELECT course_name, instructor_name, DATE_ADD(start_date, INTERVAL ? DAY), DATE_ADD(end_date, INTERVAL ? DAY), start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, ?, location, tags, status, visibility FROM appointments WHERE id=? AND deleted_at IS NULL");
            $stmt->bind_param('iiii', $offsetDays, $offsetDays, $currentUserId, $id);
        } else {
            $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, user_id, location, tags, status, visibility) SELECT course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, ?, location, tags, status, visibility FROM appointments WHERE id=? AND deleted_at IS NULL");
            $stmt->bind_param('ii', $currentUserId, $id);
        }
        $stmt->execute(); $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=7'); exit;
    }
}

// ── POST: move_event (#38) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_event') {
    $id = $_POST['event_id'] ?? null; $newStart = $_POST['new_start_date'] ?? ''; $newEnd = $_POST['new_end_date'] ?? '';
    if ($id && $newStart && $newEnd) {
        $stmt = $connection->prepare("UPDATE appointments SET start_date=?, end_date=? WHERE id=?");
        $stmt->bind_param('ssi', $newStart, $newEnd, $id); $stmt->execute(); $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2'); exit;
}

// ── POST: bulk_reschedule (#39) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_reschedule') {
    $idsRaw = trim($_POST['ids'] ?? ''); $shiftDays = (int)($_POST['shift_days'] ?? 0);
    if ($idsRaw && $shiftDays !== 0) {
        $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmt = $connection->prepare("UPDATE appointments SET start_date=DATE_ADD(start_date, INTERVAL $shiftDays DAY), end_date=DATE_ADD(end_date, INTERVAL $shiftDays DAY) WHERE id IN ($placeholders) AND deleted_at IS NULL");
            $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
}

// ── POST: auto_archive (#34) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_archive') {
    if (DEV_MODE) {
        $days = (int)($_POST['archive_days'] ?? 365);
        $cutoff = date('Y-m-d', strtotime("-$days days"));
        $now = date('Y-m-d H:i:s');
        $stmt = $connection->prepare("UPDATE appointments SET deleted_at=? WHERE end_date < ? AND deleted_at IS NULL");
        $stmt->bind_param('ss', $now, $cutoff); $stmt->execute();
        $count = $stmt->affected_rows; $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=9&n=' . $count); exit;
    }
}

// ── POST: save_filter_preset (#45) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_filter_preset') {
    $name = trim($_POST['preset_name'] ?? ''); $filters = trim($_POST['preset_filters'] ?? '');
    if ($name && $filters) {
        $stmt = $connection->prepare("INSERT INTO filter_presets (user_id, name, filters) VALUES (?,?,?) ON DUPLICATE KEY UPDATE filters=?");
        $stmt->bind_param('isss', $currentUserId, $name, $filters, $filters); $stmt->execute(); $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
}

// ── POST: create_calendar (#51) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_calendar') {
    $name = trim($_POST['cal_name'] ?? ''); $color = $_POST['cal_color'] ?? '#6B82F6';
    $desc = trim($_POST['cal_desc'] ?? '');
    if ($name) {
        $stmt = $connection->prepare("INSERT INTO calendars (user_id, name, color, description) VALUES (?,?,?,?)");
        $uid = $currentUserId ?: 0;
        $stmt->bind_param('isss', $uid, $name, $color, $desc); $stmt->execute(); $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
}

// ── POST: add_comment (#63) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_comment') {
    $eventId = (int)($_POST['event_id'] ?? 0); $body = trim($_POST['comment_body'] ?? '');
    if ($eventId && $body) {
        $uid = $currentUserId ?: 0;
        $stmt = $connection->prepare("INSERT INTO event_comments (event_id, user_id, body) VALUES (?,?,?)");
        $stmt->bind_param('iis', $eventId, $uid, $body); $stmt->execute(); $stmt->close();
        logActivity($connection, $currentUserId, $eventId, 'comment', $body);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2'); exit;
}

// ── POST: delete_all ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all') {
    if (DEV_MODE) $connection->query("TRUNCATE TABLE appointments");
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=4'); exit;
}

// ── Messages ──────────────────────────────────────────────────────────────────
$importedN = (int)($_GET['n'] ?? 0);
if (isset($_GET['success'])) {
    $successMessage = match($_GET['success']) {
        '1' => 'Event added successfully',
        '2' => 'Event updated successfully',
        '3' => 'Event deleted',
        '4' => 'All events deleted',
        '5' => "Imported $importedN event(s) successfully",
        '6' => 'Delete undone successfully',
        '7' => 'Event duplicated',
        '8' => 'Database restored successfully',
        '9' => "Auto-archived $importedN event(s)",
        default => '',
    };
}
if (isset($_GET['error'])) $errorMessage = 'An error occurred. Please check your input.';
// ── Optimistic locking conflict message (#59) ─────────────────────────────────
if (isset($_GET['conflict']) && $_GET['conflict'] === '1' && isset($_GET['success']) && $_GET['success'] === '-1') {
    $errorMessage = 'This event was modified by someone else. Reload to see the latest version.';
}
$showUndo = isset($_GET['success']) && $_GET['success'] === '3' && !empty($_SESSION['undo_id']);

// ── Fetch user calendars ──────────────────────────────────────────────────────
$userCalendars = [];
$calRes = $connection->query("SELECT * FROM calendars WHERE archived=0 ORDER BY name ASC");
if ($calRes) { while ($row = $calRes->fetch_assoc()) $userCalendars[] = $row; }

// ── Fetch filter presets ──────────────────────────────────────────────────────
$filterPresets = [];
$fpRes = $connection->query("SELECT * FROM filter_presets ORDER BY name ASC");
if ($fpRes) { while ($row = $fpRes->fetch_assoc()) $filterPresets[] = $row; }

// ── Public holidays for current year (#78) ────────────────────────────────────
function getPublicHolidays($year, $country = 'US') {
    $holidays = [];
    if ($country === 'US') {
        $holidays = [
            "$year-01-01" => "New Year's Day",
            "$year-07-04" => "Independence Day",
            "$year-12-25" => "Christmas Day",
            "$year-12-31" => "New Year's Eve",
            "$year-11-11" => "Veterans Day",
        ];
        // MLK Day (3rd Mon Jan)
        $d = new DateTime("third monday of January $year"); $holidays[$d->format('Y-m-d')] = "MLK Day";
        // Presidents Day (3rd Mon Feb)
        $d = new DateTime("third monday of February $year"); $holidays[$d->format('Y-m-d')] = "Presidents' Day";
        // Memorial Day (last Mon May)
        $d = new DateTime("last monday of May $year"); $holidays[$d->format('Y-m-d')] = "Memorial Day";
        // Labor Day (1st Mon Sep)
        $d = new DateTime("first monday of September $year"); $holidays[$d->format('Y-m-d')] = "Labor Day";
        // Thanksgiving (4th Thu Nov)
        $d = new DateTime("fourth thursday of November $year"); $holidays[$d->format('Y-m-d')] = "Thanksgiving";
    }
    return $holidays;
}
$publicHolidays = getPublicHolidays(date('Y'));

// ── Fetch events ──────────────────────────────────────────────────────────────
$q = "SELECT * FROM appointments WHERE deleted_at IS NULL";
if (REQUIRE_AUTH && $currentUserId) { $uid = (int)$currentUserId; $q .= " AND (user_id = $uid OR user_id IS NULL)"; }
$result = $connection->query($q);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $evtStart      = new DateTime($row['start_date']);
        $evtEnd        = new DateTime($row['end_date']);
        $recurrence    = $row['recurrence'] ?? 'none';
        $recurrenceEnd = !empty($row['recurrence_end']) ? new DateTime($row['recurrence_end']) : null;
        $recurrenceCount = !empty($row['recurrence_count']) ? (int)$row['recurrence_count'] : null;
        $color         = $row['color'] ?? '#6B82F6';
        $exclusions    = [];
        if (!empty($row['exclusions'])) $exclusions = array_filter(array_map('trim', explode(',', $row['exclusions'])));

        $baseEntry = [
            'id'               => $row['id'],
            'title'            => $row['course_name'] . ' - ' . $row['instructor_name'],
            'course_name'      => $row['course_name'],
            'instructor_name'  => $row['instructor_name'],
            'start'            => $row['start_date'],
            'end'              => $row['end_date'],
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'recurrence'       => $recurrence,
            'recurrence_end'   => $row['recurrence_end'],
            'recurrence_count' => $recurrenceCount,
            'color'            => $color,
            'category'         => $row['category'] ?? '',
            'notes'            => $row['notes'] ?? '',
            'event_url'        => $row['event_url'] ?? '',
            'priority'         => (int)($row['priority'] ?? 1),
            'attachment'       => $row['attachment'] ?? '',
            'location'         => $row['location'] ?? '',
            'tags'             => $row['tags'] ?? '',
            'status'           => $row['status'] ?? 'confirmed',
            'visibility'       => $row['visibility'] ?? 'public',
            'capacity'         => $row['capacity'] ?? null,
            'zoom_url'         => $row['zoom_url'] ?? '',
            'attendees'        => $row['attendees'] ?? '',
            'reminders'        => $row['reminders'] ?? '',
            'deadline'         => $row['deadline'] ?? '',
            'calendar_id'      => $row['calendar_id'] ?? null,
            'subtasks'         => $row['subtasks'] ?? '',
            'version'          => (int)($row['version'] ?? 1),
            'actual_start'     => $row['actual_start'] ?? '',
            'actual_end'       => $row['actual_end'] ?? '',
            'related_ids'      => $row['related_ids'] ?? '',
        ];

        if ($recurrence === 'none' || (!$recurrenceEnd && !$recurrenceCount)) {
            $cursor = clone $evtStart;
            while ($cursor <= $evtEnd) {
                $dateStr = $cursor->format('Y-m-d');
                if (!in_array($dateStr, $exclusions)) {
                    $entry = $baseEntry; $entry['date'] = $dateStr; $eventsFromDB[] = $entry;
                }
                $cursor->modify('+1 day');
            }
        } else {
            $duration = $evtStart->diff($evtEnd);
            $step = match($recurrence) { 'daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month', default => null };
            if ($step) {
                $occStart = clone $evtStart;
                $limit = 500; $count = 0; $occCount = 0;
                $endCondition = $recurrenceEnd ?? new DateTime('2099-12-31');
                while ($occStart <= $endCondition && $count < $limit) {
                    if ($recurrenceCount && $occCount >= $recurrenceCount) break;
                    $occEnd = (clone $occStart)->add($duration);
                    $dayCursor = clone $occStart;
                    while ($dayCursor <= $occEnd) {
                        $dateStr = $dayCursor->format('Y-m-d');
                        if (!in_array($dateStr, $exclusions)) {
                            $entry = $baseEntry; $entry['date'] = $dateStr;
                            $entry['start'] = $occStart->format('Y-m-d'); $entry['end'] = $occEnd->format('Y-m-d');
                            $eventsFromDB[] = $entry;
                        }
                        $dayCursor->modify('+1 day');
                    }
                    $occStart->modify($step); $count++; $occCount++;
                }
            }
        }
    }
}

$connection->close();
