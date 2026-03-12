<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

// Minimal auth check for AJAX
if (REQUIRE_AUTH) {
    session_start();
    if (empty($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'update_time') {
    $id        = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate   = trim($_POST['end_date'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endTime   = trim($_POST['end_time'] ?? '');

    if (!$id || !$startDate || !$endDate || !$startTime || !$endTime) {
        echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $stmt = $connection->prepare(
        "UPDATE appointments SET start_date = ?, end_date = ?, start_time = ?, end_time = ? WHERE id = ? AND deleted_at IS NULL"
    );
    $stmt->bind_param('ssssi', $startDate, $endDate, $startTime, $endTime, $id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'bulk_edit') {
    $idsRaw   = trim($_POST['ids'] ?? '');
    $color    = trim($_POST['color'] ?? '');
    $category = trim($_POST['category'] ?? '');

    if (!$idsRaw) {
        echo json_encode(['ok' => false, 'error' => 'No ids']);
        exit;
    }

    // Sanitize ids — allow only integers
    $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'error' => 'No valid ids']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sets  = [];
    $types = '';
    $vals  = [];

    if ($color !== '') {
        $sets[]  = 'color = ?';
        $types  .= 's';
        $vals[]  = $color;
    }
    if ($category !== '') {
        $sets[]  = 'category = ?';
        $types  .= 's';
        $vals[]  = $category;
    }

    if (empty($sets)) {
        echo json_encode(['ok' => false, 'error' => 'Nothing to update']);
        exit;
    }

    $setSQL = implode(', ', $sets);
    $types .= str_repeat('i', count($ids));
    $allVals = array_merge($vals, $ids);

    $stmt = $connection->prepare("UPDATE appointments SET $setSQL WHERE id IN ($placeholders) AND deleted_at IS NULL");
    $stmt->bind_param($types, ...$allVals);
    $ok = $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['ok' => $ok, 'count' => $count]);
    exit;
}

if ($action === 'bulk_delete') {
    $idsRaw = trim($_POST['ids'] ?? '');

    if (!$idsRaw) {
        echo json_encode(['ok' => false, 'error' => 'No ids']);
        exit;
    }

    $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'error' => 'No valid ids']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $now   = date('Y-m-d H:i:s');
    $types = 's' . str_repeat('i', count($ids));
    $vals  = array_merge([$now], $ids);

    $stmt = $connection->prepare("UPDATE appointments SET deleted_at = ? WHERE id IN ($placeholders) AND deleted_at IS NULL");
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['ok' => $ok, 'count' => $count]);
    exit;
}

if ($action === 'fetch_events') {
    $start = trim($_POST['start'] ?? '');
    $end   = trim($_POST['end'] ?? '');

    if (!$start || !$end) {
        echo json_encode(['ok' => false, 'error' => 'Missing start/end']);
        exit;
    }

    $currentUserId = null;
    if (REQUIRE_AUTH && isset($_SESSION['user_id'])) {
        $currentUserId = (int)$_SESSION['user_id'];
    }

    $q = "SELECT * FROM appointments WHERE deleted_at IS NULL AND start_date <= ? AND end_date >= ?";
    $types = 'ss';
    $vals  = [$end, $start];

    if (REQUIRE_AUTH && $currentUserId) {
        $q     .= " AND (user_id = ? OR user_id IS NULL)";
        $types .= 'i';
        $vals[] = $currentUserId;
    }

    $stmt = $connection->prepare($q);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $eventsFromDB = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $evtStart      = new DateTime($row['start_date']);
            $evtEnd        = new DateTime($row['end_date']);
            $recurrence    = $row['recurrence'] ?? 'none';
            $recurrenceEnd = !empty($row['recurrence_end']) ? new DateTime($row['recurrence_end']) : null;

            $exclusions = [];
            if (!empty($row['exclusions'])) {
                $exclusions = array_filter(array_map('trim', explode(',', $row['exclusions'])));
            }

            $baseEntry = [
                'id'             => $row['id'],
                'title'          => $row['course_name'] . ' - ' . $row['instructor_name'],
                'start'          => $row['start_date'],
                'end'            => $row['end_date'],
                'start_time'     => $row['start_time'],
                'end_time'       => $row['end_time'],
                'recurrence'     => $recurrence,
                'recurrence_end' => $row['recurrence_end'],
                'color'          => $row['color'] ?? '#6B82F6',
                'category'       => $row['category'] ?? '',
                'notes'          => $row['notes'] ?? '',
                'event_url'      => $row['event_url'] ?? '',
                'priority'       => (int)($row['priority'] ?? 1),
                'attachment'     => $row['attachment'] ?? '',
            ];

            $rangeStart = new DateTime($start);
            $rangeEnd   = new DateTime($end);

            if ($recurrence === 'none' || !$recurrenceEnd) {
                $cursor = clone $evtStart;
                while ($cursor <= $evtEnd) {
                    $dateStr = $cursor->format('Y-m-d');
                    $curDate = new DateTime($dateStr);
                    if ($curDate >= $rangeStart && $curDate <= $rangeEnd && !in_array($dateStr, $exclusions)) {
                        $entry         = $baseEntry;
                        $entry['date'] = $dateStr;
                        $eventsFromDB[] = $entry;
                    }
                    $cursor->modify('+1 day');
                }
            } else {
                $duration = $evtStart->diff($evtEnd);
                $step = match($recurrence) {
                    'daily'   => '+1 day',
                    'weekly'  => '+1 week',
                    'monthly' => '+1 month',
                    default   => null,
                };
                if ($step) {
                    $occStart = clone $evtStart;
                    $limit    = 500;
                    $count    = 0;
                    while ($occStart <= $recurrenceEnd && $count < $limit) {
                        $occEnd    = (clone $occStart)->add($duration);
                        $dayCursor = clone $occStart;
                        while ($dayCursor <= $occEnd) {
                            $dateStr = $dayCursor->format('Y-m-d');
                            $curDate = new DateTime($dateStr);
                            if ($curDate >= $rangeStart && $curDate <= $rangeEnd && !in_array($dateStr, $exclusions)) {
                                $entry          = $baseEntry;
                                $entry['date']  = $dateStr;
                                $entry['start'] = $occStart->format('Y-m-d');
                                $entry['end']   = $occEnd->format('Y-m-d');
                                $eventsFromDB[] = $entry;
                            }
                            $dayCursor->modify('+1 day');
                        }
                        $occStart->modify($step);
                        $count++;
                    }
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'events' => $eventsFromDB]);
    exit;
}

// ── Get event comments (#63) ──────────────────────────────────────────────────
if ($action === 'get_comments') {
    $eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['ok' => false, 'error' => 'Missing event_id']); exit; }
    $res = $connection->query("SELECT c.*, u.username FROM event_comments c LEFT JOIN users u ON c.user_id=u.id WHERE c.event_id=$eventId ORDER BY c.created_at ASC");
    $comments = [];
    if ($res) while ($row = $res->fetch_assoc()) $comments[] = $row;
    echo json_encode(['ok' => true, 'comments' => $comments]); exit;
}

// ── Get event history (#35) ───────────────────────────────────────────────────
if ($action === 'get_history') {
    $eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['ok' => false, 'error' => 'Missing event_id']); exit; }
    $res = $connection->query("SELECT h.*, u.username FROM event_history h LEFT JOIN users u ON h.user_id=u.id WHERE h.event_id=$eventId ORDER BY h.changed_at DESC LIMIT 20");
    $history = [];
    if ($res) while ($row = $res->fetch_assoc()) $history[] = $row;
    echo json_encode(['ok' => true, 'history' => $history]); exit;
}

// ── Save webhook (#69) ────────────────────────────────────────────────────────
if ($action === 'save_webhook') {
    $url = trim($_POST['url'] ?? '');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid URL']); exit;
    }
    $currentUserId = null;
    if (isset($_SESSION['user_id'])) $currentUserId = (int)$_SESSION['user_id'];
    $stmt = $connection->prepare("INSERT INTO webhooks (user_id, url) VALUES (?,?) ON DUPLICATE KEY UPDATE url=?");
    if ($stmt) { $stmt->bind_param('iss', $currentUserId, $url, $url); $stmt->execute(); $stmt->close(); }
    echo json_encode(['ok' => true]); exit;
}

// ── Get activity feed (#64) ───────────────────────────────────────────────────
if ($action === 'get_activity') {
    $limit = min(50, (int)($_GET['limit'] ?? 20));
    $res = $connection->query("SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT $limit");
    $feed = [];
    if ($res) while ($row = $res->fetch_assoc()) $feed[] = $row;
    echo json_encode(['ok' => true, 'feed' => $feed]); exit;
}

// ── Get notifications (#93) ───────────────────────────────────────────────────
if ($action === 'get_notifications') {
    $currentUserId = null;
    if (isset($_SESSION['user_id'])) $currentUserId = (int)$_SESSION['user_id'];
    $q = "SELECT * FROM notifications WHERE read_at IS NULL";
    if ($currentUserId) $q .= " AND user_id=$currentUserId";
    $q .= " ORDER BY created_at DESC LIMIT 20";
    $res = $connection->query($q);
    $notifs = [];
    if ($res) while ($row = $res->fetch_assoc()) $notifs[] = $row;
    echo json_encode(['ok' => true, 'notifications' => $notifs]); exit;
}

// ── Get filter presets (#45) ──────────────────────────────────────────────────
if ($action === 'get_filter_presets') {
    $res = $connection->query("SELECT * FROM filter_presets ORDER BY name ASC");
    $presets = [];
    if ($res) while ($row = $res->fetch_assoc()) $presets[] = $row;
    echo json_encode(['ok' => true, 'presets' => $presets]); exit;
}

// ── Statistics endpoint (#83) ─────────────────────────────────────────────────
if ($action === 'get_stats') {
    $catCount = []; $totalHrs = 0;
    $res = $connection->query("SELECT category, start_time, end_time FROM appointments WHERE deleted_at IS NULL");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cat = $row['category'] ?: 'Uncategorized';
            $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
            if ($row['start_time'] && $row['end_time']) {
                $start = strtotime($row['start_time']); $end = strtotime($row['end_time']);
                if ($end > $start) $totalHrs += ($end - $start) / 3600;
            }
        }
    }
    echo json_encode(['ok' => true, 'categories' => $catCount, 'total_hours' => round($totalHrs, 1)]); exit;
}

// ── Add comment via AJAX (#63) ────────────────────────────────────────────────
if ($action === 'add_comment') {
    $eventId = (int)($_POST['event_id'] ?? 0);
    $body    = trim($_POST['comment_body'] ?? '');
    $currentUserId = null;
    if (isset($_SESSION['user_id'])) $currentUserId = (int)$_SESSION['user_id'];
    if (!$eventId || !$body) { echo json_encode(['ok' => false, 'error' => 'Missing params']); exit; }
    $uid = $currentUserId ?: 0;
    $stmt = $connection->prepare("INSERT INTO event_comments (event_id, user_id, body) VALUES (?,?,?)");
    if ($stmt) { $stmt->bind_param('iis', $eventId, $uid, $body); $ok = $stmt->execute(); $stmt->close(); }
    echo json_encode(['ok' => $ok ?? false]); exit;
}

// ── Archive calendar (#60) ────────────────────────────────────────────────────
if ($action === 'archive_calendar') {
    $calId = (int)($_POST['cal_id'] ?? 0);
    if (!$calId) { echo json_encode(['ok' => false, 'error' => 'Missing cal_id']); exit; }
    $ok = $connection->query("UPDATE calendars SET archived=1 WHERE id=$calId");
    echo json_encode(['ok' => (bool)$ok]); exit;
}

// ── Import ICS from URL (#56, #71, #72) ──────────────────────────────────────
if ($action === 'import_ics_url') {
    $url = trim($_POST['url'] ?? '');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid URL']); exit;
    }
    // Fetch the ICS content
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'CalendarApp/1.0']]);
    $icsContent = @file_get_contents($url, false, $ctx);
    if (!$icsContent) {
        echo json_encode(['ok' => false, 'error' => 'Could not fetch URL. Check the URL is publicly accessible.']); exit;
    }
    // Parse VEVENT blocks
    $count = 0;
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $events = [];
    if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $icsContent, $matches)) {
        foreach ($matches[1] as $block) {
            $get = function($key) use ($block) {
                if (preg_match('/^' . $key . '[^:]*:(.+)$/m', $block, $m)) return trim($m[1]);
                return '';
            };
            $summary  = $get('SUMMARY');
            $dtstart  = $get('DTSTART');
            $dtend    = $get('DTEND');
            $location = $get('LOCATION');
            $desc     = $get('DESCRIPTION');
            $rrule    = $get('RRULE');
            $cats     = $get('CATEGORIES');

            // Parse date/time
            $parseDate = function($dt) {
                $dt = preg_replace('/T.*/', '', $dt); // strip time for date
                if (strlen($dt) === 8) return substr($dt,0,4).'-'.substr($dt,4,2).'-'.substr($dt,6,2);
                return '';
            };
            $parseTime = function($dt) {
                if (strpos($dt,'T') === false) return '';
                $t = preg_replace('/.*T/', '', $dt);
                $t = preg_replace('/Z$/', '', $t);
                if (strlen($t) >= 6) return substr($t,0,2).':'.substr($t,2,2).':'.substr($t,4,2);
                return '';
            };

            $startDate = $parseDate($dtstart);
            $endDate   = $parseDate($dtend) ?: $startDate;
            $startTime = $parseTime($dtstart);
            $endTime   = $parseTime($dtend);
            if (!$startDate || !$summary) continue;

            // Birthday/anniversary auto-recurring (#79)
            $recurrence = 'none';
            $recurrenceEnd = null;
            if ($rrule) {
                if (stripos($rrule, 'FREQ=YEARLY') !== false)  $recurrence = 'yearly';
                elseif (stripos($rrule, 'FREQ=MONTHLY') !== false) $recurrence = 'monthly';
                elseif (stripos($rrule, 'FREQ=WEEKLY') !== false)  $recurrence = 'weekly';
                elseif (stripos($rrule, 'FREQ=DAILY') !== false)   $recurrence = 'daily';
                if (preg_match('/UNTIL=(\d{8})/', $rrule, $um)) {
                    $ud = $um[1]; $recurrenceEnd = substr($ud,0,4).'-'.substr($ud,4,2).'-'.substr($ud,6,2);
                }
            }
            if (stripos($cats, 'BIRTHDAY') !== false || stripos($summary, 'birthday') !== false
                || stripos($summary, 'anniversary') !== false) {
                $recurrence = 'yearly';
            }
            if ($recurrence === 'yearly') $recurrence = 'none'; // yearly not natively supported; treat as annual single

            $stmt2 = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, location, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if ($stmt2) {
                $instr = 'Imported';
                $color = '#6B82F6';
                $cat   = $cats ?: '';
                $stmt2->bind_param('ssssssssssssi',
                    $summary, $instr, $startDate, $endDate, $startTime, $endTime,
                    $recurrence, $recurrenceEnd, $color, $cat, $desc, $location, $currentUserId
                );
                if ($stmt2->execute()) $count++;
                $stmt2->close();
            }
        }
    }
    echo json_encode(['ok' => true, 'count' => $count]); exit;
}

// ── Import CSV with field mapping (#76) ───────────────────────────────────────
if ($action === 'import_csv_mapped') {
    $mappingJson = trim($_POST['mapping'] ?? '');
    $rowsJson    = trim($_POST['rows'] ?? '');
    if (!$mappingJson || !$rowsJson) { echo json_encode(['ok' => false, 'error' => 'Missing data']); exit; }
    $mapping = json_decode($mappingJson, true);
    $rows    = json_decode($rowsJson, true);
    if (!$mapping || !$rows) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $count = 0;
    foreach ($rows as $row) {
        $get = function($field) use ($mapping, $row) {
            if (!isset($mapping[$field])) return '';
            return trim($row[$mapping[$field]] ?? '');
        };
        $course   = $get('course_name');
        $instr    = $get('instructor_name') ?: 'Imported';
        $start    = $get('start_date');
        $end      = $get('end_date') ?: $start;
        $stime    = $get('start_time');
        $etime    = $get('end_time');
        $color    = $get('color') ?: '#6B82F6';
        $cat      = $get('category');
        $notes    = $get('notes');
        $url      = $get('event_url');
        $prio     = (int)($get('priority') ?: 1);
        $loc      = $get('location');
        $tags     = $get('tags');
        if (!$course || !$start) continue;
        // 12 params: sssssssssssi
        $stmt4 = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, color, category, notes, location, tags, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        if ($stmt4) {
            $stmt4->bind_param('sssssssssssi', $course, $instr, $start, $end, $stime, $etime, $color, $cat, $notes, $loc, $tags, $currentUserId);
            if ($stmt4->execute()) $count++;
            $stmt4->close();
        }
    }
    echo json_encode(['ok' => true, 'count' => $count]); exit;
}

// ── Send test email reminder (#74) ────────────────────────────────────────────
if ($action === 'send_test_email') {
    $to = trim($_POST['to'] ?? '');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid email address']); exit;
    }
    // Use PHP mail() — requires server mail configuration or SMTP via config
    $subject = 'Calendar Reminder Test';
    $body    = 'This is a test email reminder from your calendar application.';
    $headers = 'From: calendar@localhost' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    $ok = @mail($to, $subject, $body, $headers);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'mail() failed — configure SMTP in php.ini or use a mail library']); exit;
}

// ── Send test SMS reminder (#75) ──────────────────────────────────────────────
if ($action === 'send_test_sms') {
    $to = trim($_POST['to'] ?? '');
    if (!$to) { echo json_encode(['ok' => false, 'error' => 'Missing phone number']); exit; }
    // Check if Twilio constants are configured
    if (!defined('TWILIO_SID') || !TWILIO_SID) {
        echo json_encode(['ok' => false, 'error' => 'Twilio not configured. Add TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM to config.php']); exit;
    }
    // Basic Twilio REST API call (no SDK required)
    $sid   = TWILIO_SID;
    $token = TWILIO_TOKEN;
    $from  = TWILIO_FROM;
    $msg   = 'Test SMS from your Calendar app.';
    $data  = http_build_query(['To' => $to, 'From' => $from, 'Body' => $msg]);
    $url2  = 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json';
    $ctx   = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Authorization: Basic ' . base64_encode("$sid:$token") . "\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => $data,
        'timeout' => 10,
    ]]);
    $res = @file_get_contents($url2, false, $ctx);
    $ok  = $res && json_decode($res) && isset(json_decode($res)->sid);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Twilio API call failed']); exit;
}

// ── Save filter preset (#45) ──────────────────────────────────────────────────
if ($action === 'save_filter_preset') {
    // Auto-migrate filter_presets table
    $connection->query("CREATE TABLE IF NOT EXISTS `filter_presets` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        name VARCHAR(100) NOT NULL,
        filters TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $name    = trim($_POST['preset_name'] ?? '');
    $filters = trim($_POST['preset_filters'] ?? '');
    if (!$name || !$filters) { echo json_encode(['ok' => false, 'error' => 'Missing name or filters']); exit; }
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $stmt = $connection->prepare("INSERT INTO filter_presets (user_id, name, filters) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iss', $currentUserId, $name, $filters);
        $ok = $stmt->execute();
        $newId = $connection->insert_id;
        $stmt->close();
        echo json_encode(['ok' => $ok, 'id' => $newId]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'DB error']);
    }
    exit;
}

// ── Delete filter preset (#45) ────────────────────────────────────────────────
if ($action === 'delete_filter_preset') {
    $id = (int)($_POST['preset_id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing preset_id']); exit; }
    $ok = $connection->query("DELETE FROM filter_presets WHERE id=$id");
    echo json_encode(['ok' => (bool)$ok]); exit;
}

// ── Send invite email (#49) ───────────────────────────────────────────────────
if ($action === 'send_invite_email') {
    $eventId        = (int)($_POST['event_id'] ?? 0);
    $attendeeEmails = trim($_POST['attendee_emails'] ?? '');
    if (!$eventId || !$attendeeEmails) {
        echo json_encode(['ok' => false, 'error' => 'Missing event_id or attendee_emails']); exit;
    }
    // Fetch event details
    $res = $connection->query("SELECT * FROM appointments WHERE id=$eventId AND deleted_at IS NULL LIMIT 1");
    if (!$res || !($event = $res->fetch_assoc())) {
        echo json_encode(['ok' => false, 'error' => 'Event not found']); exit;
    }
    $emails = array_filter(array_map('trim', explode(',', $attendeeEmails)));
    if (empty($emails)) { echo json_encode(['ok' => false, 'error' => 'No valid email addresses']); exit; }

    $title    = $event['course_name'] . ' - ' . $event['instructor_name'];
    $startDt  = $event['start_date'] . ($event['start_time'] ? ' ' . $event['start_time'] : '');
    $endDt    = $event['end_date']   . ($event['end_time']   ? ' ' . $event['end_time']   : '');
    $location = $event['location'] ?? '';
    $notes    = $event['notes']    ?? '';

    // Build ICS VEVENT
    $dtStamp  = gmdate('Ymd\THis\Z');
    $dtStart  = str_replace('-', '', $event['start_date']);
    $dtEnd    = str_replace('-', '', $event['end_date']);
    if ($event['start_time']) { $dtStart .= 'T' . str_replace(':', '', substr($event['start_time'], 0, 5)) . '00'; }
    if ($event['end_time'])   { $dtEnd   .= 'T' . str_replace(':', '', substr($event['end_time'],   0, 5)) . '00'; }
    $uid = 'invite-' . $eventId . '-' . time() . '@calendar';
    $icsBody = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Calendar App//EN\r\nMETHOD:REQUEST\r\n"
        . "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:$dtStamp\r\nDTSTART:$dtStart\r\nDTEND:$dtEnd\r\n"
        . "SUMMARY:" . addcslashes($title, ',;\\') . "\r\n"
        . ($location ? "LOCATION:" . addcslashes($location, ',;\\') . "\r\n" : '')
        . ($notes    ? "DESCRIPTION:" . addcslashes(str_replace(["\r\n","\n","\r"], "\\n", $notes), ',;\\') . "\r\n" : '')
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";

    $icsB64   = base64_encode($icsBody);
    $boundary = '----=_Part_' . md5(uniqid());
    $from     = defined('SMTP_FROM') && SMTP_FROM ? SMTP_FROM : 'calendar@localhost';

    $sent = 0;
    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $subject = 'Invitation: ' . $title;
        $textBody = "You have been invited to:\n\n"
            . "Title:    $title\n"
            . "Date:     $startDt\n"
            . ($endDt !== $startDt ? "End:      $endDt\n" : '')
            . ($location ? "Location: $location\n" : '')
            . ($notes ? "\nNotes:\n$notes\n" : '');

        $headers = "From: $from\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $textBody . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/calendar; charset=UTF-8; method=REQUEST; name=invite.ics\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=invite.ics\r\n\r\n"
            . chunk_split($icsB64) . "\r\n"
            . "--$boundary--";

        if (@mail($email, $subject, $body, $headers)) $sent++;
    }
    echo json_encode(['ok' => true, 'sent' => $sent]); exit;
}

// ── Get event permissions (#62) ───────────────────────────────────────────────
if ($action === 'get_event_permissions') {
    $connection->query("CREATE TABLE IF NOT EXISTS `event_permissions` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        user_id INT NOT NULL,
        permission ENUM('view','edit') NOT NULL DEFAULT 'view',
        granted_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(event_id), INDEX(user_id)
    )");
    $eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['ok' => false, 'error' => 'Missing event_id']); exit; }
    $res = $connection->query("SELECT ep.*, u.username FROM event_permissions ep LEFT JOIN users u ON ep.user_id=u.id WHERE ep.event_id=$eventId");
    $perms = [];
    if ($res) while ($row = $res->fetch_assoc()) $perms[] = $row;
    echo json_encode(['ok' => true, 'permissions' => $perms]); exit;
}

// ── Grant event permission (#62) ──────────────────────────────────────────────
if ($action === 'grant_event_permission') {
    $connection->query("CREATE TABLE IF NOT EXISTS `event_permissions` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        user_id INT NOT NULL,
        permission ENUM('view','edit') NOT NULL DEFAULT 'view',
        granted_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(event_id), INDEX(user_id)
    )");
    $eventId   = (int)($_POST['event_id'] ?? 0);
    $username  = trim($_POST['username'] ?? '');
    $perm      = in_array($_POST['permission'] ?? '', ['view','edit']) ? $_POST['permission'] : 'view';
    $grantedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if (!$eventId || !$username) { echo json_encode(['ok' => false, 'error' => 'Missing params']); exit; }
    // Look up user_id by username
    $uRes = $connection->query("SELECT id FROM users WHERE username='" . $connection->real_escape_string($username) . "' LIMIT 1");
    if (!$uRes || !($uRow = $uRes->fetch_assoc())) { echo json_encode(['ok' => false, 'error' => 'User not found']); exit; }
    $userId = (int)$uRow['id'];
    $stmt = $connection->prepare("INSERT INTO event_permissions (event_id, user_id, permission, granted_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE permission=?");
    if ($stmt) { $stmt->bind_param('iisss', $eventId, $userId, $perm, $grantedBy, $perm); $ok = $stmt->execute(); $stmt->close(); }
    echo json_encode(['ok' => $ok ?? false]); exit;
}

// ── Revoke event permission (#62) ─────────────────────────────────────────────
if ($action === 'revoke_event_permission') {
    $permId = (int)($_POST['perm_id'] ?? 0);
    if (!$permId) { echo json_encode(['ok' => false, 'error' => 'Missing perm_id']); exit; }
    $ok = $connection->query("DELETE FROM event_permissions WHERE id=$permId");
    echo json_encode(['ok' => (bool)$ok]); exit;
}

// ── Calendar shares — get (#66) ───────────────────────────────────────────────
if ($action === 'get_calendar_shares') {
    $connection->query("CREATE TABLE IF NOT EXISTS `calendar_shares` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        calendar_id INT NOT NULL,
        user_id INT NOT NULL,
        permission ENUM('view','edit') NOT NULL DEFAULT 'view',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(calendar_id), INDEX(user_id)
    )");
    $calId = (int)($_GET['cal_id'] ?? $_POST['cal_id'] ?? 0);
    if (!$calId) { echo json_encode(['ok' => false, 'error' => 'Missing cal_id']); exit; }
    $res = $connection->query("SELECT cs.*, u.username FROM calendar_shares cs LEFT JOIN users u ON cs.user_id=u.id WHERE cs.calendar_id=$calId");
    $shares = [];
    if ($res) while ($row = $res->fetch_assoc()) $shares[] = $row;
    echo json_encode(['ok' => true, 'shares' => $shares]); exit;
}

// ── Calendar shares — share (#66) ─────────────────────────────────────────────
if ($action === 'share_calendar') {
    $connection->query("CREATE TABLE IF NOT EXISTS `calendar_shares` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        calendar_id INT NOT NULL,
        user_id INT NOT NULL,
        permission ENUM('view','edit') NOT NULL DEFAULT 'view',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(calendar_id), INDEX(user_id)
    )");
    $calId    = (int)($_POST['cal_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $perm     = in_array($_POST['permission'] ?? '', ['view','edit']) ? $_POST['permission'] : 'view';
    if (!$calId || !$username) { echo json_encode(['ok' => false, 'error' => 'Missing params']); exit; }
    $uRes = $connection->query("SELECT id FROM users WHERE username='" . $connection->real_escape_string($username) . "' LIMIT 1");
    if (!$uRes || !($uRow = $uRes->fetch_assoc())) { echo json_encode(['ok' => false, 'error' => 'User not found']); exit; }
    $userId = (int)$uRow['id'];
    $stmt = $connection->prepare("INSERT INTO calendar_shares (calendar_id, user_id, permission) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission=?");
    if ($stmt) { $stmt->bind_param('iiss', $calId, $userId, $perm, $perm); $ok = $stmt->execute(); $stmt->close(); }
    echo json_encode(['ok' => $ok ?? false]); exit;
}

// ── Calendar shares — unshare (#66) ───────────────────────────────────────────
if ($action === 'unshare_calendar') {
    $shareId = (int)($_POST['share_id'] ?? 0);
    if (!$shareId) { echo json_encode(['ok' => false, 'error' => 'Missing share_id']); exit; }
    $ok = $connection->query("DELETE FROM calendar_shares WHERE id=$shareId");
    echo json_encode(['ok' => (bool)$ok]); exit;
}

// ── Set calendar group (#55) ───────────────────────────────────────────────────
if ($action === 'set_calendar_group') {
    // Auto-migrate group_name column
    $chk = $connection->query("SHOW COLUMNS FROM calendars LIKE 'group_name'");
    if ($chk && $chk->num_rows === 0) {
        $connection->query("ALTER TABLE calendars ADD COLUMN group_name VARCHAR(100) DEFAULT NULL");
    }
    $calId     = (int)($_POST['cal_id'] ?? 0);
    $groupName = trim($_POST['group_name'] ?? '');
    if (!$calId) { echo json_encode(['ok' => false, 'error' => 'Missing cal_id']); exit; }
    $stmt = $connection->prepare("UPDATE calendars SET group_name=? WHERE id=?");
    if ($stmt) { $stmt->bind_param('si', $groupName, $calId); $ok = $stmt->execute(); $stmt->close(); }
    echo json_encode(['ok' => $ok ?? false]); exit;
}

// ── Add sub-event (#61) ───────────────────────────────────────────────────────
if ($action === 'add_sub_event') {
    // Auto-migrate parent_id column
    $chk = $connection->query("SHOW COLUMNS FROM appointments LIKE 'parent_id'");
    if ($chk && $chk->num_rows === 0) {
        $connection->query("ALTER TABLE appointments ADD COLUMN parent_id INT NULL DEFAULT NULL");
    }
    $parentId  = (int)($_POST['parent_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    if (!$parentId || !$title || !$startDate) {
        echo json_encode(['ok' => false, 'error' => 'Missing params']); exit;
    }
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $instr = 'Sub-task'; $none = 'none'; $color = '#9ca3af'; $prio = 1;
    $endDate = $startDate;
    $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, recurrence, color, priority, user_id, parent_id) VALUES (?,?,?,?,?,?,?,?,?)");
    if ($stmt) {
        $stmt->bind_param('ssssssiis', $title, $instr, $startDate, $endDate, $none, $color, $prio, $currentUserId, $parentId);
        $ok = $stmt->execute();
        $newId = $connection->insert_id;
        $stmt->close();
        echo json_encode(['ok' => $ok, 'id' => $newId]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'DB error']);
    }
    exit;
}

// ── Get sub-events (#61) ──────────────────────────────────────────────────────
if ($action === 'get_sub_events') {
    // Auto-migrate parent_id column
    $chk = $connection->query("SHOW COLUMNS FROM appointments LIKE 'parent_id'");
    if ($chk && $chk->num_rows === 0) {
        $connection->query("ALTER TABLE appointments ADD COLUMN parent_id INT NULL DEFAULT NULL");
    }
    $parentId = (int)($_GET['parent_id'] ?? $_POST['parent_id'] ?? 0);
    if (!$parentId) { echo json_encode(['ok' => false, 'error' => 'Missing parent_id']); exit; }
    $res = $connection->query("SELECT * FROM appointments WHERE parent_id=$parentId AND deleted_at IS NULL ORDER BY start_date ASC");
    $subs = [];
    if ($res) while ($row = $res->fetch_assoc()) $subs[] = $row;
    echo json_encode(['ok' => true, 'sub_events' => $subs]); exit;
}

// ── Get notifications (#66/#67 — attendee change notifications) ───────────────
if ($action === 'mark_notification_read') {
    $notifId = (int)($_POST['notif_id'] ?? 0);
    if (!$notifId) { echo json_encode(['ok' => false, 'error' => 'Missing notif_id']); exit; }
    // Support is_read column as well as read_at
    $chk = $connection->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
    if ($chk && $chk->num_rows === 0) {
        $connection->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT NOT NULL DEFAULT 0");
    }
    $now = date('Y-m-d H:i:s');
    $ok = $connection->query("UPDATE notifications SET read_at='$now', is_read=1 WHERE id=$notifId");
    echo json_encode(['ok' => (bool)$ok]); exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
