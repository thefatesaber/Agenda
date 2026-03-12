<?php
// This script writes all updated app files to both locations.
// Run with: php _write_files.php

$paths = [
    'C:/Users/Asimiir/Desktop/agenda/',
    'C:/wamp64/www/agenda/',
];

// ============================================================
// calendar.php
// ============================================================
$calendarPhp = <<<'PHPEOF'
<?php
require_once __DIR__ . '/config.php';
include __DIR__ . '/connection.php';

$successMessage = '';
$errorMessage   = '';
$eventsFromDB   = [];
$showUndo       = false;

// Auto-migrate: recurrence columns
$check = $connection->query("SHOW COLUMNS FROM appointments LIKE 'recurrence'");
if ($check && $check->num_rows === 0) {
    $connection->query("ALTER TABLE appointments ADD COLUMN recurrence VARCHAR(10) NOT NULL DEFAULT 'none'");
    $connection->query("ALTER TABLE appointments ADD COLUMN recurrence_end DATE NULL");
}

// Auto-migrate: new columns
$newCols = [
    'color'      => "ALTER TABLE appointments ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#6B82F6'",
    'category'   => "ALTER TABLE appointments ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT ''",
    'notes'      => "ALTER TABLE appointments ADD COLUMN notes TEXT NULL",
    'deleted_at' => "ALTER TABLE appointments ADD COLUMN deleted_at DATETIME NULL",
    'user_id'    => "ALTER TABLE appointments ADD COLUMN user_id INT NULL",
    'exclusions' => "ALTER TABLE appointments ADD COLUMN exclusions TEXT NULL",
    'attachment' => "ALTER TABLE appointments ADD COLUMN attachment VARCHAR(255) NULL",
    'event_url'  => "ALTER TABLE appointments ADD COLUMN event_url VARCHAR(500) NULL",
    'priority'   => "ALTER TABLE appointments ADD COLUMN priority TINYINT NOT NULL DEFAULT 1",
];
foreach ($newCols as $col => $sql) {
    $chk = $connection->query("SHOW COLUMNS FROM appointments LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $connection->query($sql);
    }
}

// Create users table if not exists
$connection->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    remember_token VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Add remember_token column if missing
$chkTok = $connection->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
if ($chkTok && $chkTok->num_rows === 0) {
    $connection->query("ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL");
}

// Create uploads directory if not exists
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// Auth
include __DIR__ . '/auth.php';

$currentUserId = $_SESSION['user_id'] ?? null;

// GET: export_csv
if (($_GET['action'] ?? '') === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="events.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','course_name','instructor_name','start_date','end_date','start_time','end_time','recurrence','recurrence_end','color','category','notes','event_url','priority']);
    $q = "SELECT id, course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority FROM appointments WHERE deleted_at IS NULL";
    if (REQUIRE_AUTH && $currentUserId) {
        $uid = (int)$currentUserId;
        $q  .= " AND (user_id = $uid OR user_id IS NULL)";
    }
    $res = $connection->query($q);
    if ($res) { while ($row = $res->fetch_assoc()) { fputcsv($out, $row); } }
    fclose($out);
    exit;
}

// GET: export_ical
if (($_GET['action'] ?? '') === 'export_ical') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="events.ics"');
    $q = "SELECT * FROM appointments WHERE deleted_at IS NULL";
    if (REQUIRE_AUTH && $currentUserId) {
        $uid = (int)$currentUserId;
        $q  .= " AND (user_id = $uid OR user_id IS NULL)";
    }
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
            $urlLine = !empty($row['event_url']) ? "URL:" . $row['event_url'] . "\r\n" : '';
            echo "BEGIN:VEVENT\r\nUID:$uidv\r\nDTSTART:$dtstart\r\nDTEND:$dtend\r\nSUMMARY:$summary\r\n";
            if ($desc) echo "DESCRIPTION:$desc\r\n";
            if ($urlLine) echo $urlLine;
            if (!empty($row['category'])) echo "CATEGORIES:" . addcslashes($row['category'], ',;\\') . "\r\n";
            echo "END:VEVENT\r\n";
        }
    }
    echo "END:VCALENDAR\r\n";
    exit;
}

// GET: export_sql (DEV_MODE only)
if (($_GET['action'] ?? '') === 'export_sql' && DEV_MODE) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="backup.sql"');
    $res = $connection->query("SELECT * FROM appointments WHERE deleted_at IS NULL");
    echo "-- Calendar DB Backup " . date('Y-m-d H:i:s') . "\n";
    echo "-- TRUNCATE TABLE appointments;\n\n";
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $vals = array_map(function($v) use ($connection) {
                return $v === null ? 'NULL' : "'" . $connection->real_escape_string($v) . "'";
            }, $row);
            $cols = implode(', ', array_keys($row));
            $vstr = implode(', ', $vals);
            echo "INSERT INTO appointments ($cols) VALUES ($vstr);\n";
        }
    }
    exit;
}

// POST: restore_sql (DEV_MODE only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_sql' && DEV_MODE) {
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
        $connection->query("TRUNCATE TABLE appointments");
        $stmts = array_filter(array_map('trim', explode(";\n", $sql)));
        $restored = 0;
        foreach ($stmts as $s) {
            if (stripos($s, 'INSERT INTO') === 0) {
                if ($connection->query($s)) $restored++;
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=8&n=' . $restored);
        exit;
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=8');
    exit;
}

// POST: import_csv (also handles .ics)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $imported = 0;
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fname   = $_FILES['csv_file']['name'];
        $content = file_get_contents($_FILES['csv_file']['tmp_name']);
        $isIcal  = (strtolower(substr($fname, -4)) === '.ics') || (strpos($content, 'BEGIN:VCALENDAR') !== false);

        if ($isIcal) {
            $lines   = preg_split('/\r?\n/', $content);
            $inEvent = false;
            $vevent  = [];
            foreach ($lines as $line) {
                $line = rtrim($line);
                if ($line === 'BEGIN:VEVENT') { $inEvent = true; $vevent = []; continue; }
                if ($line === 'END:VEVENT' && $inEvent) {
                    $inEvent = false;
                    $dtstart = $vevent['DTSTART'] ?? '';
                    $dtend   = $vevent['DTEND']   ?? $dtstart;
                    if (strpos($dtstart, ':') !== false) $dtstart = substr($dtstart, strrpos($dtstart, ':') + 1);
                    if (strpos($dtend,   ':') !== false) $dtend   = substr($dtend,   strrpos($dtend,   ':') + 1);
                    $sDate = $sTime = $eDate = $eTime = '';
                    if (strlen($dtstart) >= 8) {
                        $sDate = substr($dtstart,0,4).'-'.substr($dtstart,4,2).'-'.substr($dtstart,6,2);
                    }
                    if (strlen($dtstart) >= 15 && $dtstart[8] === 'T') {
                        $sTime = substr($dtstart,9,2).':'.substr($dtstart,11,2);
                    }
                    if (strlen($dtend) >= 8) {
                        $eDate = substr($dtend,0,4).'-'.substr($dtend,4,2).'-'.substr($dtend,6,2);
                    }
                    if (strlen($dtend) >= 15 && $dtend[8] === 'T') {
                        $eTime = substr($dtend,9,2).':'.substr($dtend,11,2);
                    }
                    if (!$sDate || !$eDate) continue;
                    $summary = $vevent['SUMMARY'] ?? '';
                    $desc    = $vevent['DESCRIPTION'] ?? '';
                    $cat     = $vevent['CATEGORIES'] ?? '';
                    $url     = $vevent['URL'] ?? '';
                    $parts   = explode(' - ', $summary, 2);
                    $course  = trim($parts[0]);
                    $instr   = isset($parts[1]) ? trim($parts[1]) : ($desc ? trim($desc) : '');
                    if (!$course) continue;
                    $c2 = $connection->real_escape_string($course);
                    $i2 = $connection->real_escape_string($instr);
                    $s2 = $connection->real_escape_string($sDate);
                    $e2 = $connection->real_escape_string($eDate);
                    $st2= $connection->real_escape_string($sTime);
                    $et2= $connection->real_escape_string($eTime);
                    $ca2= $connection->real_escape_string($cat);
                    $d2 = $connection->real_escape_string($desc);
                    $u2 = $connection->real_escape_string($url);
                    $uid2 = $currentUserId ? (int)$currentUserId : 'NULL';
                    if ($connection->query("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, color, category, notes, event_url, priority, user_id) VALUES ('$c2','$i2','$s2','$e2','$st2','$et2','none','#6B82F6','$ca2','$d2','$u2',1,$uid2)")) $imported++;
                    continue;
                }
                if ($inEvent && strpos($line, ':') !== false) {
                    list($key, $val) = explode(':', $line, 2);
                    $key = preg_replace('/;.*/', '', $key);
                    $vevent[$key] = $val;
                }
            }
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 8) continue;
                    $course   = trim($row[1] ?? '');
                    $instr    = trim($row[2] ?? '');
                    $start    = trim($row[3] ?? '');
                    $end      = trim($row[4] ?? '');
                    $stime    = trim($row[5] ?? '');
                    $etime    = trim($row[6] ?? '');
                    $recur    = trim($row[7] ?? 'none');
                    $recurEnd = (trim($row[8] ?? '') !== '') ? trim($row[8]) : null;
                    $color    = trim($row[9] ?? '#6B82F6') ?: '#6B82F6';
                    $cat      = trim($row[10] ?? '');
                    $notes    = trim($row[11] ?? '');
                    $evUrl    = trim($row[12] ?? '');
                    $priority = max(1, min(3, (int)($row[13] ?? 1)));
                    if (!$course || !$start || !$end) continue;
                    $c2  = $connection->real_escape_string($course);
                    $i2  = $connection->real_escape_string($instr);
                    $s2  = $connection->real_escape_string($start);
                    $e2  = $connection->real_escape_string($end);
                    $st2 = $connection->real_escape_string($stime);
                    $et2 = $connection->real_escape_string($etime);
                    $rc2 = $connection->real_escape_string($recur);
                    $re2 = $recurEnd ? "'" . $connection->real_escape_string($recurEnd) . "'" : 'NULL';
                    $cl2 = $connection->real_escape_string($color);
                    $ca2 = $connection->real_escape_string($category);
                    $n2  = $connection->real_escape_string($notes);
                    $eu2 = $connection->real_escape_string($evUrl);
                    $pr2 = (int)$priority;
                    $uid2 = $currentUserId ? (int)$currentUserId : 'NULL';
                    if ($connection->query("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, user_id) VALUES ('$c2','$i2','$s2','$e2','$st2','$et2','$rc2',$re2,'$cl2','$ca2','$n2','$eu2',$pr2,$uid2)")) $imported++;
                }
                fclose($handle);
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=5&n=' . $imported);
    exit;
}

// Helper: handle attachment upload
function handleAttachmentUpload($uploadsDir) {
    if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $orig = basename($_FILES['attachment']['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
    $name = $safe . '_' . uniqid() . '.' . $ext;
    $dest = $uploadsDir . '/' . $name;
    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
        return 'uploads/' . $name;
    }
    return null;
}

// POST: add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $course        = trim($_POST['course_name'] ?? '');
    $instructor    = trim($_POST['instructor_name'] ?? '');
    $start         = $_POST['start_date'] ?? '';
    $end           = $_POST['end_date'] ?? '';
    $startTime     = $_POST['start_time'] ?? '';
    $endTime       = $_POST['end_time'] ?? '';
    $recurrence    = $_POST['recurrence'] ?? 'none';
    $recurrenceEnd = ($recurrence !== 'none' && !empty($_POST['recurrence_end'])) ? $_POST['recurrence_end'] : null;
    $color         = $_POST['color'] ?? '#6B82F6';
    $category      = trim($_POST['category'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $eventUrl      = trim($_POST['event_url'] ?? '');
    $priority      = max(1, min(3, (int)($_POST['priority'] ?? 1)));
    $attachment    = handleAttachmentUpload($uploadsDir);

    // Conflict detection (warning only)
    $conflictId = null;
    if ($course && $start && $end && $startTime && $endTime) {
        $cq = $connection->prepare(
            "SELECT id FROM appointments WHERE deleted_at IS NULL AND start_date <= ? AND end_date >= ? AND start_time < ? AND end_time > ? LIMIT 1"
        );
        $cq->bind_param('ssss', $end, $start, $endTime, $startTime);
        $cq->execute();
        $cq->bind_result($conflictId);
        $cq->fetch();
        $cq->close();
    }

    if ($course && $instructor && $start && $end) {
        $c2  = $connection->real_escape_string($course);
        $i2  = $connection->real_escape_string($instructor);
        $s2  = $connection->real_escape_string($start);
        $e2  = $connection->real_escape_string($end);
        $st2 = $connection->real_escape_string($startTime);
        $et2 = $connection->real_escape_string($endTime);
        $rc2 = $connection->real_escape_string($recurrence);
        $re2 = $recurrenceEnd ? "'" . $connection->real_escape_string($recurrenceEnd) . "'" : 'NULL';
        $cl2 = $connection->real_escape_string($color);
        $ca2 = $connection->real_escape_string($category);
        $n2  = $connection->real_escape_string($notes);
        $eu2 = $connection->real_escape_string($eventUrl);
        $pr2 = (int)$priority;
        $at2 = $attachment ? "'" . $connection->real_escape_string($attachment) . "'" : 'NULL';
        $uid2 = $currentUserId ? (int)$currentUserId : 'NULL';
        $connection->query("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, attachment, user_id) VALUES ('$c2','$i2','$s2','$e2','$st2','$et2','$rc2',$re2,'$cl2','$ca2','$n2','$eu2',$pr2,$at2,$uid2)");
        if ($conflictId) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?warning=conflict&conflicting_id=' . (int)$conflictId . '&success=1');
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        }
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
}

// POST: edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id            = (int)($_POST['event_id'] ?? 0);
    $course        = trim($_POST['course_name'] ?? '');
    $instructor    = trim($_POST['instructor_name'] ?? '');
    $start         = $_POST['start_date'] ?? '';
    $end           = $_POST['end_date'] ?? '';
    $startTime     = $_POST['start_time'] ?? '';
    $endTime       = $_POST['end_time'] ?? '';
    $recurrence    = $_POST['recurrence'] ?? 'none';
    $recurrenceEnd = ($recurrence !== 'none' && !empty($_POST['recurrence_end'])) ? $_POST['recurrence_end'] : null;
    $color         = $_POST['color'] ?? '#6B82F6';
    $category      = trim($_POST['category'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $eventUrl      = trim($_POST['event_url'] ?? '');
    $priority      = max(1, min(3, (int)($_POST['priority'] ?? 1)));
    $attachment    = handleAttachmentUpload($uploadsDir);

    // Conflict detection
    $conflictId = null;
    if ($id && $start && $end && $startTime && $endTime) {
        $cq = $connection->prepare(
            "SELECT id FROM appointments WHERE deleted_at IS NULL AND start_date <= ? AND end_date >= ? AND start_time < ? AND end_time > ? AND id != ? LIMIT 1"
        );
        $cq->bind_param('ssssi', $end, $start, $endTime, $startTime, $id);
        $cq->execute();
        $cq->bind_result($conflictId);
        $cq->fetch();
        $cq->close();
    }

    if ($id && $course && $instructor && $start && $end) {
        $c2  = $connection->real_escape_string($course);
        $i2  = $connection->real_escape_string($instructor);
        $s2  = $connection->real_escape_string($start);
        $e2  = $connection->real_escape_string($end);
        $st2 = $connection->real_escape_string($startTime);
        $et2 = $connection->real_escape_string($endTime);
        $rc2 = $connection->real_escape_string($recurrence);
        $re2 = $recurrenceEnd ? "'" . $connection->real_escape_string($recurrenceEnd) . "'" : 'NULL';
        $cl2 = $connection->real_escape_string($color);
        $ca2 = $connection->real_escape_string($category);
        $n2  = $connection->real_escape_string($notes);
        $eu2 = $connection->real_escape_string($eventUrl);
        $pr2 = (int)$priority;
        $atSql = '';
        if ($attachment) {
            $at2 = $connection->real_escape_string($attachment);
            $atSql = ", attachment='$at2'";
        }
        $connection->query("UPDATE appointments SET course_name='$c2', instructor_name='$i2', start_date='$s2', end_date='$e2', start_time='$st2', end_time='$et2', recurrence='$rc2', recurrence_end=$re2, color='$cl2', category='$ca2', notes='$n2', event_url='$eu2', priority=$pr2 $atSql WHERE id=$id");
        if ($conflictId) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?warning=conflict&conflicting_id=' . (int)$conflictId . '&success=2');
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2');
        }
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2');
        exit;
    }
}

// POST: edit_occurrence
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_occurrence') {
    $id             = $_POST['event_id'] ?? null;
    $occurrenceDate = $_POST['occurrence_date'] ?? null;
    $course         = trim($_POST['course_name'] ?? '');
    $instructor     = trim($_POST['instructor_name'] ?? '');
    $start          = $_POST['start_date'] ?? '';
    $end            = $_POST['end_date'] ?? '';
    $startTime      = $_POST['start_time'] ?? '';
    $endTime        = $_POST['end_time'] ?? '';
    $color          = $_POST['color'] ?? '#6B82F6';
    $category       = trim($_POST['category'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');
    $eventUrl       = trim($_POST['event_url'] ?? '');
    $priority       = max(1, min(3, (int)($_POST['priority'] ?? 1)));

    if ($id && $occurrenceDate && $course && $start && $end) {
        $resExcl = $connection->prepare("SELECT exclusions FROM appointments WHERE id=?");
        $resExcl->bind_param('i', $id);
        $resExcl->execute();
        $resExcl->bind_result($existingExcl);
        $resExcl->fetch();
        $resExcl->close();

        $exclList = array_filter(array_map('trim', explode(',', $existingExcl ?? '')));
        if (!in_array($occurrenceDate, $exclList)) { $exclList[] = $occurrenceDate; }
        $newExcl = implode(',', $exclList);

        $stmtUpd = $connection->prepare("UPDATE appointments SET exclusions=? WHERE id=?");
        $stmtUpd->bind_param('si', $newExcl, $id);
        $stmtUpd->execute();
        $stmtUpd->close();

        $c2 = $connection->real_escape_string($course);
        $i2 = $connection->real_escape_string($instructor);
        $s2 = $connection->real_escape_string($start);
        $e2 = $connection->real_escape_string($end);
        $st2= $connection->real_escape_string($startTime);
        $et2= $connection->real_escape_string($endTime);
        $cl2= $connection->real_escape_string($color);
        $ca2= $connection->real_escape_string($category);
        $n2 = $connection->real_escape_string($notes);
        $eu2= $connection->real_escape_string($eventUrl);
        $pr2= (int)$priority;
        $uid2 = $currentUserId ? (int)$currentUserId : 'NULL';
        $connection->query("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, color, category, notes, event_url, priority, user_id) VALUES ('$c2','$i2','$s2','$e2','$st2','$et2','none','$cl2','$ca2','$n2','$eu2',$pr2,$uid2)");
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=2');
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=2');
        exit;
    }
}

// POST: delete (soft-delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = $_POST['event_id'] ?? null;
    if ($id) {
        $now = date('Y-m-d H:i:s');
        $stmt = $connection->prepare("UPDATE appointments SET deleted_at=? WHERE id=?");
        $stmt->bind_param('si', $now, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['undo_id'] = (int)$id;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=3');
        exit;
    }
}

// POST: undo_delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo_delete') {
    $undoId = $_SESSION['undo_id'] ?? null;
    if ($undoId) {
        $stmt = $connection->prepare("UPDATE appointments SET deleted_at=NULL WHERE id=?");
        $stmt->bind_param('i', $undoId);
        $stmt->execute();
        $stmt->close();
        unset($_SESSION['undo_id']);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=6');
    exit;
}

// POST: duplicate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'duplicate') {
    $id = (int)($_POST['event_id'] ?? 0);
    if ($id) {
        $uid2 = $currentUserId ? (int)$currentUserId : 'NULL';
        $connection->query("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, user_id) SELECT course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, event_url, priority, $uid2 FROM appointments WHERE id=$id AND deleted_at IS NULL");
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=7');
        exit;
    }
}

// POST: delete_all (DEV_MODE only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all') {
    if (DEV_MODE) {
        $connection->query("TRUNCATE TABLE appointments");
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=4');
    exit;
}

// Messages
$importedN = (int)($_GET['n'] ?? 0);
if (isset($_GET['success'])) {
    $successMessage = match($_GET['success']) {
        '1' => 'Appointment added successfully',
        '2' => 'Appointment updated successfully',
        '3' => 'Appointment deleted',
        '4' => 'All appointments deleted',
        '5' => "Imported $importedN event(s) successfully",
        '6' => 'Delete undone successfully',
        '7' => 'Event duplicated',
        '8' => "DB restored ($importedN rows)",
        default => '',
    };
}
if (isset($_GET['error'])) {
    $errorMessage = 'An error occurred. Please check your input.';
}

$showUndo = isset($_GET['success']) && $_GET['success'] === '3' && !empty($_SESSION['undo_id']);

// Fetch events
$q = "SELECT * FROM appointments WHERE deleted_at IS NULL";
if (REQUIRE_AUTH && $currentUserId) {
    $uid = (int)$currentUserId;
    $q  .= " AND (user_id = $uid OR user_id IS NULL)";
}
$result = $connection->query($q);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $evtStart      = new DateTime($row['start_date']);
        $evtEnd        = new DateTime($row['end_date']);
        $recurrence    = $row['recurrence'] ?? 'none';
        $recurrenceEnd = !empty($row['recurrence_end']) ? new DateTime($row['recurrence_end']) : null;
        $color         = $row['color'] ?? '#6B82F6';
        $category      = $row['category'] ?? '';
        $notes         = $row['notes'] ?? '';
        $eventUrl      = $row['event_url'] ?? '';
        $priority      = (int)($row['priority'] ?? 1);
        $attachment    = $row['attachment'] ?? '';

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
            'color'          => $color,
            'category'       => $category,
            'notes'          => $notes,
            'event_url'      => $eventUrl,
            'priority'       => $priority,
            'attachment'     => $attachment,
        ];

        if ($recurrence === 'none' || !$recurrenceEnd) {
            $cursor = clone $evtStart;
            while ($cursor <= $evtEnd) {
                $dateStr = $cursor->format('Y-m-d');
                if (!in_array($dateStr, $exclusions)) {
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
                        if (!in_array($dateStr, $exclusions)) {
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

$connection->close();
PHPEOF;

// ============================================================
// ajax.php
// ============================================================
$ajaxPhp = <<<'PHPEOF'
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

// update_time: drag & drop / resize
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

// fetch_events: range-based AJAX fetch (feature 15)
if ($action === 'fetch_events') {
    $start = trim($_GET['start'] ?? $_POST['start'] ?? '');
    $end   = trim($_GET['end']   ?? $_POST['end']   ?? '');
    if (!$start || !$end) {
        echo json_encode(['ok' => false, 'error' => 'Missing start/end']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'] ?? null;
    $q = "SELECT * FROM appointments WHERE deleted_at IS NULL AND start_date <= ? AND end_date >= ?";
    $params = [$end, $start];
    $types  = 'ss';

    if (REQUIRE_AUTH && $currentUserId) {
        $uid = (int)$currentUserId;
        $q  .= " AND (user_id = $uid OR user_id IS NULL)";
    }

    $stmt = $connection->prepare($q);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $eventsOut = [];
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

        if ($recurrence === 'none' || !$recurrenceEnd) {
            $cursor = clone $evtStart;
            while ($cursor <= $evtEnd) {
                $dateStr = $cursor->format('Y-m-d');
                if ($dateStr >= $start && $dateStr <= $end && !in_array($dateStr, $exclusions)) {
                    $entry         = $baseEntry;
                    $entry['date'] = $dateStr;
                    $eventsOut[]   = $entry;
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
                $limit = 500; $count = 0;
                while ($occStart <= $recurrenceEnd && $count < $limit) {
                    $occEnd    = (clone $occStart)->add($duration);
                    $dayCursor = clone $occStart;
                    while ($dayCursor <= $occEnd) {
                        $dateStr = $dayCursor->format('Y-m-d');
                        if ($dateStr >= $start && $dateStr <= $end && !in_array($dateStr, $exclusions)) {
                            $entry          = $baseEntry;
                            $entry['date']  = $dateStr;
                            $entry['start'] = $occStart->format('Y-m-d');
                            $entry['end']   = $occEnd->format('Y-m-d');
                            $eventsOut[]    = $entry;
                        }
                        $dayCursor->modify('+1 day');
                    }
                    $occStart->modify($step);
                    $count++;
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'events' => $eventsOut]);
    exit;
}

// bulk_edit: change color/category for multiple events
if ($action === 'bulk_edit') {
    $ids      = $_POST['ids'] ?? [];
    $color    = trim($_POST['color']    ?? '');
    $category = trim($_POST['category'] ?? '');
    if (empty($ids)) { echo json_encode(['ok' => false, 'error' => 'No IDs']); exit; }

    $ids = array_map('intval', (array)$ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types  = str_repeat('i', count($ids));
    $setClauses = [];
    $setVals    = [];
    $setTypes   = '';
    if ($color)    { $setClauses[] = "color=?";    $setVals[] = $color;    $setTypes .= 's'; }
    if ($category !== '') { $setClauses[] = "category=?"; $setVals[] = $category; $setTypes .= 's'; }
    if (empty($setClauses)) { echo json_encode(['ok' => false, 'error' => 'Nothing to update']); exit; }

    $sql  = "UPDATE appointments SET " . implode(', ', $setClauses) . " WHERE id IN ($placeholders) AND deleted_at IS NULL";
    $stmt = $connection->prepare($sql);
    $allParams = array_merge($setVals, $ids);
    $allTypes  = $setTypes . $types;
    $stmt->bind_param($allTypes, ...$allParams);
    $ok = $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    echo json_encode(['ok' => $ok, 'count' => $count]);
    exit;
}

// bulk_delete: soft-delete multiple events
if ($action === 'bulk_delete') {
    $ids = $_POST['ids'] ?? [];
    if (empty($ids)) { echo json_encode(['ok' => false, 'error' => 'No IDs']); exit; }
    $ids  = array_map('intval', (array)$ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $now   = date('Y-m-d H:i:s');
    $stmt  = $connection->prepare("UPDATE appointments SET deleted_at=? WHERE id IN ($placeholders) AND deleted_at IS NULL");
    $params = array_merge([$now], $ids);
    $stmt->bind_param('s' . $types, ...$params);
    $ok    = $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    echo json_encode(['ok' => $ok, 'count' => $count]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
PHPEOF;

// ============================================================
// login.php (with remember me)
// ============================================================
$loginPhp = <<<'PHPEOF'
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

// Check remember-me cookie BEFORE session_start to set lifetime
$rememberCookie = $_COOKIE['remember_token'] ?? '';

if ($rememberCookie) {
    session_set_cookie_params(['lifetime' => 30 * 24 * 3600]);
}

session_start();

// Auto-login via remember-me cookie
if (!empty($rememberCookie) && empty($_SESSION['user_id'])) {
    $tok = $connection->real_escape_string($rememberCookie);
    $res = $connection->query("SELECT id, username FROM users WHERE remember_token='$tok' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $_SESSION['user_id']  = $row['id'];
        $_SESSION['username'] = $row['username'];
        header('Location: index.php');
        exit;
    }
}

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $rememberMe = !empty($_POST['remember_me']);

    if ($username && $password) {
        $stmt = $connection->prepare("SELECT id, password_hash FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($uid, $hash);
        $stmt->fetch();
        $stmt->close();

        if ($uid && password_verify($password, $hash)) {
            if ($rememberMe) {
                session_set_cookie_params(['lifetime' => 30 * 24 * 3600]);
            }
            session_regenerate_id(true);
            $_SESSION['user_id']  = $uid;
            $_SESSION['username'] = $username;

            if ($rememberMe) {
                $token = bin2hex(random_bytes(32));
                $connection->query("UPDATE users SET remember_token='$token' WHERE id=$uid");
                setcookie('remember_token', $token, time() + 30 * 24 * 3600, '/', '', false, true);
            }

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please enter both username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – Calendar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1>&#128197; Course Calendar</h1>
    </header>

    <div class="auth-container">
        <h2>Sign In</h2>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" class="auth-form">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autocomplete="username">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">

            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin-top:0.75rem;">
                <input type="checkbox" name="remember_me" value="1" style="width:auto;padding:0;">
                Remember me for 30 days
            </label>

            <button type="submit" class="auth-btn">Sign In</button>
        </form>
        <p style="text-align:center; margin-top:1rem;">
            Don't have an account? <a href="register.php" class="auth-link">Register</a>
        </p>
    </div>

    <script>
    (function () {
        const saved = localStorage.getItem('darkMode');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (saved === '1' || (saved === null && prefersDark)) {
            document.body.classList.add('dark');
        }
    })();
    </script>
</body>
</html>
PHPEOF;

// ============================================================
// Write files
// ============================================================
$files = [
    'calendar.php' => $calendarPhp,
    'ajax.php'     => $ajaxPhp,
    'login.php'    => $loginPhp,
];

foreach ($paths as $dir) {
    foreach ($files as $name => $content) {
        $fp = $dir . $name;
        if (file_put_contents($fp, $content) !== false) {
            echo "Wrote: $fp\n";
        } else {
            echo "FAILED: $fp\n";
        }
    }
}
echo "Done writing PHP files.\n";
