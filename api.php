<?php
/**
 * REST API with API key authentication (#70)
 * Endpoints: /api.php?action=...&api_key=...
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_REQUEST['action'] ?? '';

// ── API Key Authentication ─────────────────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_REQUEST['api_key'] ?? '';
$apiUserId = null;

// Create api_keys table if not exists
$connection->query("CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME NULL
)");

if ($apiKey) {
    $stmt = $connection->prepare("SELECT user_id FROM api_keys WHERE api_key=?");
    if ($stmt) {
        $stmt->bind_param('s', $apiKey);
        $stmt->execute();
        $stmt->bind_result($apiUserId);
        $stmt->fetch();
        $stmt->close();
        if ($apiUserId) {
            $connection->query("UPDATE api_keys SET last_used=NOW() WHERE api_key='" . $connection->real_escape_string($apiKey) . "'");
        }
    }
}

// Allow unauthenticated access in DEV_MODE or for certain actions
$publicActions = ['generate_key', 'list_events', 'get_event', 'ping'];
$requiresAuth  = !DEV_MODE && !in_array($action, $publicActions);

if ($requiresAuth && !$apiUserId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Provide valid api_key.']);
    exit;
}

// ── Helper functions ──────────────────────────────────────────────────────────
function buildEventQuery($conn, $apiUserId, $conditions = '') {
    $q = "SELECT * FROM appointments WHERE deleted_at IS NULL";
    if (REQUIRE_AUTH && $apiUserId) {
        $uid = (int)$apiUserId;
        $q  .= " AND (user_id=$uid OR user_id IS NULL)";
    }
    if ($conditions) $q .= " AND $conditions";
    return $q;
}

function formatEvent($row) {
    return [
        'id'             => (int)$row['id'],
        'title'          => $row['course_name'] . ' - ' . $row['instructor_name'],
        'course_name'    => $row['course_name'],
        'instructor_name'=> $row['instructor_name'],
        'start_date'     => $row['start_date'],
        'end_date'       => $row['end_date'],
        'start_time'     => $row['start_time'],
        'end_time'       => $row['end_time'],
        'recurrence'     => $row['recurrence'],
        'color'          => $row['color'],
        'category'       => $row['category'],
        'notes'          => $row['notes'],
        'priority'       => (int)$row['priority'],
        'location'       => $row['location'] ?? '',
        'tags'           => $row['tags'] ?? '',
        'status'         => $row['status'] ?? 'confirmed',
        'visibility'     => $row['visibility'] ?? 'public',
        'event_url'      => $row['event_url'] ?? '',
        'zoom_url'       => $row['zoom_url'] ?? '',
        'attendees'      => $row['attendees'] ?? '',
        'deadline'       => $row['deadline'] ?? null,
        'calendar_id'    => $row['calendar_id'] ?? null,
        'created_at'     => null,
        'updated_at'     => null,
    ];
}

// ── Actions ────────────────────────────────────────────────────────────────────

// Ping / health check
if ($action === 'ping' || $action === '') {
    echo json_encode(['ok' => true, 'message' => 'Calendar API v1.0', 'timestamp' => time()]);
    exit;
}

// Generate API key
if ($action === 'generate_key') {
    if (!DEV_MODE) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden in production']); exit; }
    $key = bin2hex(random_bytes(32));
    $uid = (int)($_REQUEST['user_id'] ?? 0);
    $label = trim($_REQUEST['label'] ?? 'Default');
    $stmt = $connection->prepare("INSERT INTO api_keys (user_id, api_key, label) VALUES (?,?,?)");
    if ($stmt) { $stmt->bind_param('iss', $uid, $key, $label); $stmt->execute(); $stmt->close(); }
    echo json_encode(['ok' => true, 'api_key' => $key, 'user_id' => $uid]);
    exit;
}

// List API keys (dev only)
if ($action === 'list_keys') {
    if (!DEV_MODE) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    $res = $connection->query("SELECT id, user_id, label, api_key, created_at, last_used FROM api_keys ORDER BY created_at DESC");
    $keys = [];
    if ($res) while ($row = $res->fetch_assoc()) $keys[] = $row;
    echo json_encode(['ok' => true, 'keys' => $keys]); exit;
}

// GET /api.php?action=list_events[&start=YYYY-MM-DD&end=YYYY-MM-DD&category=X&priority=N]
if ($action === 'list_events' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $conditions = [];
    $start = trim($_GET['start'] ?? '');
    $end   = trim($_GET['end']   ?? '');
    $category = trim($_GET['category'] ?? '');
    $priority = (int)($_GET['priority'] ?? 0);
    $calId    = (int)($_GET['calendar_id'] ?? 0);

    if ($start) $conditions[] = "start_date >= '" . $connection->real_escape_string($start) . "'";
    if ($end)   $conditions[] = "end_date <= '"   . $connection->real_escape_string($end) . "'";
    if ($category) $conditions[] = "category = '" . $connection->real_escape_string($category) . "'";
    if ($priority) $conditions[] = "priority = $priority";
    if ($calId)    $conditions[] = "calendar_id = $calId";

    $q = buildEventQuery($connection, $apiUserId, implode(' AND ', $conditions));
    $q .= " ORDER BY start_date ASC, start_time ASC LIMIT 500";
    $res = $connection->query($q);
    $events = [];
    if ($res) while ($row = $res->fetch_assoc()) $events[] = formatEvent($row);
    echo json_encode(['ok' => true, 'count' => count($events), 'events' => $events]); exit;
}

// GET /api.php?action=get_event&id=N
if ($action === 'get_event' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
    $res = $connection->query("SELECT * FROM appointments WHERE id=$id AND deleted_at IS NULL LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc())) {
        http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Event not found']); exit;
    }
    echo json_encode(['ok' => true, 'event' => formatEvent($row)]); exit;
}

// POST /api.php?action=create_event
if ($action === 'create_event' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $course   = trim($data['course_name']   ?? $data['title']       ?? '');
    $instr    = trim($data['instructor_name'] ?? '');
    $start    = trim($data['start_date']    ?? '');
    $end      = trim($data['end_date']      ?? $start);
    $st       = trim($data['start_time']    ?? '');
    $et       = trim($data['end_time']      ?? '');
    $color    = trim($data['color']         ?? '#6B82F6');
    $category = trim($data['category']      ?? '');
    $notes    = trim($data['notes']         ?? '');
    $priority = (int)($data['priority']     ?? 1);
    $recur    = trim($data['recurrence']    ?? 'none');
    $recurEnd = trim($data['recurrence_end'] ?? '') ?: null;
    $location = trim($data['location']      ?? '');
    $tags     = trim($data['tags']          ?? '');
    $status   = trim($data['status']        ?? 'confirmed');
    $eventUrl = trim($data['event_url']     ?? '');
    $zoomUrl  = trim($data['zoom_url']      ?? '');

    if (!$course || !$start) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'course_name and start_date required']); exit;
    }

    $uid = $apiUserId ?: 0;
    $stmt = $connection->prepare("INSERT INTO appointments (course_name, instructor_name, start_date, end_date, start_time, end_time, recurrence, recurrence_end, color, category, notes, priority, user_id, location, tags, status, event_url, zoom_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssssssssiissss', $course, $instr, $start, $end, $st, $et, $recur, $recurEnd, $color, $category, $notes, $priority, $uid, $location, $tags, $status, $eventUrl, $zoomUrl);
    $ok = $stmt->execute();
    $newId = $connection->insert_id;
    $stmt->close();
    if ($ok) {
        echo json_encode(['ok' => true, 'id' => $newId, 'message' => 'Event created']);
    } else {
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB error: '.$connection->error]);
    }
    exit;
}

// PUT /api.php?action=update_event&id=N
if ($action === 'update_event') {
    $id = (int)($_REQUEST['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $sets = []; $types = ''; $vals = [];
    $allowedFields = ['course_name','instructor_name','start_date','end_date','start_time','end_time',
        'recurrence','recurrence_end','color','category','notes','priority','location','tags','status',
        'visibility','event_url','zoom_url','attendees','reminders','deadline'];
    foreach ($allowedFields as $f) {
        if (isset($data[$f])) {
            $sets[] = "`$f` = ?";
            $val = $data[$f];
            if ($f === 'priority') { $types .= 'i'; $vals[] = (int)$val; }
            else { $types .= 's'; $vals[] = $val === '' ? null : (string)$val; }
        }
    }
    if (empty($sets)) { echo json_encode(['ok'=>false,'error'=>'Nothing to update']); exit; }
    $types .= 'i'; $vals[] = $id;
    $stmt = $connection->prepare("UPDATE appointments SET " . implode(', ', $sets) . " WHERE id=? AND deleted_at IS NULL");
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Updated' : 'Failed']); exit;
}

// DELETE /api.php?action=delete_event&id=N
if ($action === 'delete_event') {
    $id = (int)($_REQUEST['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
    $now = date('Y-m-d H:i:s');
    $stmt = $connection->prepare("UPDATE appointments SET deleted_at=? WHERE id=? AND deleted_at IS NULL");
    $stmt->bind_param('si', $now, $id); $ok = $stmt->execute(); $stmt->close();
    echo json_encode(['ok' => $ok]); exit;
}

// GET /api.php?action=list_calendars
if ($action === 'list_calendars') {
    $res = $connection->query("SELECT * FROM calendars WHERE archived=0 ORDER BY name ASC");
    $cals = [];
    if ($res) while ($row = $res->fetch_assoc()) $cals[] = $row;
    echo json_encode(['ok' => true, 'calendars' => $cals]); exit;
}

// GET /api.php?action=shared_calendar&token=TOKEN (#57)
if ($action === 'shared_calendar') {
    $token = trim($_GET['token'] ?? '');
    if (!$token) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing token']); exit; }
    $stmt = $connection->prepare("SELECT id FROM calendars WHERE share_token=? AND archived=0 LIMIT 1");
    $stmt->bind_param('s', $token); $stmt->execute(); $stmt->bind_result($calId); $stmt->fetch(); $stmt->close();
    if (!$calId) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Calendar not found']); exit; }
    $res = $connection->query("SELECT * FROM appointments WHERE calendar_id=$calId AND deleted_at IS NULL ORDER BY start_date ASC");
    $events = [];
    if ($res) while ($row = $res->fetch_assoc()) $events[] = formatEvent($row);
    echo json_encode(['ok' => true, 'events' => $events]); exit;
}

// GET /api.php?action=embed_calendar&token=TOKEN (#58 — iframe-friendly HTML)
if ($action === 'embed_calendar') {
    header('Content-Type: text/html; charset=utf-8');
    $token = trim($_GET['token'] ?? '');
    $events2 = [];
    if ($token) {
        $stmt = $connection->prepare("SELECT id FROM calendars WHERE share_token=? LIMIT 1");
        $stmt->bind_param('s', $token); $stmt->execute(); $stmt->bind_result($calId); $stmt->fetch(); $stmt->close();
        if ($calId) {
            $res = $connection->query("SELECT * FROM appointments WHERE calendar_id=$calId AND deleted_at IS NULL ORDER BY start_date ASC LIMIT 100");
            if ($res) while ($row = $res->fetch_assoc()) $events2[] = $row;
        }
    } else {
        $res = $connection->query("SELECT * FROM appointments WHERE deleted_at IS NULL ORDER BY start_date ASC LIMIT 50");
        if ($res) while ($row = $res->fetch_assoc()) $events2[] = $row;
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Calendar Embed</title>';
    echo '<style>body{font-family:sans-serif;font-size:13px;margin:0;padding:8px;}table{width:100%;border-collapse:collapse;}th,td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;}th{background:#f9fafb;font-weight:bold;}</style></head><body>';
    echo '<table><thead><tr><th>Date</th><th>Time</th><th>Event</th><th>Category</th></tr></thead><tbody>';
    foreach ($events2 as $row) {
        $time = $row['start_time'] ? substr($row['start_time'],0,5) : '';
        echo '<tr><td>'.htmlspecialchars($row['start_date']).'</td><td>'.htmlspecialchars($time).'</td><td>'.htmlspecialchars($row['course_name']).'</td><td>'.htmlspecialchars($row['category']).'</td></tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// GET /api.php?action=free_slots&date=YYYY-MM-DD (#81)
if ($action === 'free_slots') {
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    $res = $connection->query("SELECT start_time, end_time FROM appointments WHERE start_date='$date' AND deleted_at IS NULL AND start_time IS NOT NULL ORDER BY start_time ASC");
    $busy = [];
    if ($res) while ($row = $res->fetch_assoc()) {
        $s = (int)substr($row['start_time'],0,2)*60+(int)substr($row['start_time'],3,2);
        $e = (int)substr($row['end_time'],0,2)*60+(int)substr($row['end_time'],3,2);
        $busy[] = ['start' => $s, 'end' => $e];
    }
    $workStart = 8*60; $workEnd = 18*60; $cur = $workStart; $slots = [];
    foreach ($busy as $b) {
        if ($b['start'] > $cur) $slots[] = ['start' => sprintf('%02d:%02d', intdiv($cur,60), $cur%60), 'end' => sprintf('%02d:%02d', intdiv($b['start'],60), $b['start']%60), 'duration_min' => $b['start']-$cur];
        $cur = max($cur, $b['end']);
    }
    if ($cur < $workEnd) $slots[] = ['start' => sprintf('%02d:%02d', intdiv($cur,60), $cur%60), 'end' => sprintf('%02d:%02d', intdiv($workEnd,60), $workEnd%60), 'duration_min' => $workEnd-$cur];
    echo json_encode(['ok' => true, 'date' => $date, 'free_slots' => $slots]); exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action. Available: ping, list_events, get_event, create_event, update_event, delete_event, list_calendars, shared_calendar, embed_calendar, free_slots, generate_key']);
