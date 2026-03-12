<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

// Ensure uploads/avatars exists
if (!is_dir(__DIR__ . '/uploads/avatars')) {
    mkdir(__DIR__ . '/uploads/avatars', 0755, true);
}

// Auth
include __DIR__ . '/auth.php';
$currentUserId = $_SESSION['user_id'] ?? null;

// Create users table migration for new columns
$userCols = [
    'display_name' => "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL",
    'avatar'       => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL",
    'timezone'     => "ALTER TABLE users ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC'",
];
foreach ($userCols as $col => $sql) {
    $chk = $connection->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($chk && $chk->num_rows === 0) $connection->query($sql);
}

// Create api_keys table
$connection->query("CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME NULL
)");

$message = '';
$error   = '';

// Fetch current user
$user = null;
if ($currentUserId) {
    $stmt = $connection->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

// POST: update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $displayName = trim($_POST['display_name'] ?? '');
    $timezone    = trim($_POST['timezone'] ?? 'UTC');

    // Handle avatar upload
    $avatar = $user['avatar'] ?? null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed) && $_FILES['avatar']['size'] < 2 * 1024 * 1024) {
            $newName = 'avatar_' . $currentUserId . '_' . time() . '.' . $ext;
            $dest    = __DIR__ . '/uploads/avatars/' . $newName;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                $avatar = 'uploads/avatars/' . $newName;
            }
        } else {
            $error = 'Avatar must be JPG/PNG/GIF/WebP under 2MB.';
        }
    }

    if (!$error && $currentUserId) {
        $stmt = $connection->prepare("UPDATE users SET display_name=?, timezone=?, avatar=? WHERE id=?");
        $stmt->bind_param('sssi', $displayName, $timezone, $avatar, $currentUserId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['display_name'] = $displayName;
        $message = 'Profile updated successfully!';
        // Refresh user data
        $stmt2 = $connection->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $stmt2->bind_param('i', $currentUserId);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $user = $res2 ? $res2->fetch_assoc() : $user;
        $stmt2->close();
    }
}

// POST: change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $oldPass  = $_POST['old_password'] ?? '';
    $newPass  = $_POST['new_password'] ?? '';
    $newPass2 = $_POST['new_password2'] ?? '';
    if ($newPass !== $newPass2) { $error = 'New passwords do not match.'; }
    elseif (strlen($newPass) < 6) { $error = 'Password must be at least 6 characters.'; }
    elseif ($user && !password_verify($oldPass, $user['password_hash'])) { $error = 'Old password is incorrect.'; }
    else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $connection->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->bind_param('si', $hash, $currentUserId);
        $stmt->execute();
        $stmt->close();
        $message = 'Password changed successfully!';
    }
}

// POST: generate API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_api_key') {
    $label = trim($_POST['key_label'] ?? 'My Key');
    $key = bin2hex(random_bytes(32));
    $uid = (int)$currentUserId;
    $stmt = $connection->prepare("INSERT INTO api_keys (user_id, api_key, label) VALUES (?,?,?)");
    $stmt->bind_param('iss', $uid, $key, $label);
    $stmt->execute();
    $stmt->close();
    $message = 'API key generated: ' . $key;
}

// POST: delete API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_api_key') {
    $keyId = (int)($_POST['key_id'] ?? 0);
    if ($keyId && $currentUserId) {
        $stmt = $connection->prepare("DELETE FROM api_keys WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $keyId, $currentUserId);
        $stmt->execute();
        $stmt->close();
        $message = 'API key deleted.';
    }
}

// Fetch API keys
$apiKeys = [];
if ($currentUserId) {
    $res = $connection->query("SELECT * FROM api_keys WHERE user_id=" . (int)$currentUserId . " ORDER BY created_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $apiKeys[] = $row;
}

$timezones = [
    'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'America/Toronto', 'America/Vancouver', 'Europe/London', 'Europe/Paris', 'Europe/Berlin',
    'Europe/Madrid', 'Europe/Rome', 'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Singapore',
    'Asia/Kolkata', 'Asia/Dubai', 'Australia/Sydney', 'Australia/Melbourne', 'Pacific/Auckland',
];

$connection->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile — Calendar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1>&#128100; User Profile</h1>
    <a href="index.php" class="dark-toggle-btn" style="text-decoration:none;">&#8592; Back to Calendar</a>
</header>

<div class="clock-container" style="font-size:1rem;padding:0.5rem;">
    <?php echo !empty($user) ? 'Logged in as <strong>' . htmlspecialchars($user['username']) . '</strong>' : 'Not logged in'; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="max-width:700px;margin:1.5rem auto;display:flex;flex-direction:column;gap:1.5rem;">

    <?php if (!$user && !REQUIRE_AUTH): ?>
    <div class="alert" style="background:#fef9c3;color:#854d0e;text-align:center;padding:1rem;">
        Authentication is disabled. Profile features work best with a logged-in user.
    </div>
    <?php endif; ?>

    <!-- Profile info (#99) -->
    <div style="background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding:1.5rem;">
        <h2 style="margin-bottom:1.2rem;font-size:1.1rem;color:var(--primary-dark);">&#128100; Profile Information</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">

            <!-- Avatar upload -->
            <div style="display:flex;align-items:center;gap:1.2rem;margin-bottom:1rem;">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Avatar" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--primary);">
                <?php else: ?>
                    <div style="width:72px;height:72px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:2rem;border:3px solid var(--primary);">&#128100;</div>
                <?php endif; ?>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Profile Photo</label>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:0.85rem;">
                    <div style="font-size:0.75rem;color:#9ca3af;margin-top:2px;">JPG, PNG, GIF, WebP — max 2MB</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Username</label>
                    <input type="text" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#6b7280;">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Display Name</label>
                    <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" placeholder="How to display your name" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;">
                </div>
            </div>

            <!-- Timezone (#100) -->
            <div style="margin-top:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Timezone</label>
                <select name="timezone" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= $tz ?>" <?= ($user['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:0.8rem;color:#6b7280;margin-top:4px;">
                    Current server time: <?= date('Y-m-d H:i T') ?>
                    <?php if (!empty($user['timezone'])): ?>
                    | Your timezone: <?php try { echo (new DateTime('now', new DateTimeZone($user['timezone'])))->format('H:i T'); } catch(Exception $e) { echo $user['timezone']; } ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Member since</label>
                <div style="color:#6b7280;font-size:0.9rem;"><?= htmlspecialchars($user['created_at'] ?? 'N/A') ?></div>
            </div>

            <button type="submit" style="margin-top:1.2rem;padding:10px 24px;background:var(--primary);color:white;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
                &#128190; Save Profile
            </button>
        </form>
    </div>

    <!-- Change password -->
    <?php if (REQUIRE_AUTH && $currentUserId): ?>
    <div style="background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding:1.5rem;">
        <h2 style="margin-bottom:1.2rem;font-size:1.1rem;color:var(--primary-dark);">&#128274; Change Password</h2>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Current Password</label>
                    <input type="password" name="old_password" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;">New Password</label>
                        <input type="password" name="new_password" required minlength="6" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Confirm New Password</label>
                        <input type="password" name="new_password2" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;">
                    </div>
                </div>
            </div>
            <button type="submit" style="margin-top:1.2rem;padding:10px 24px;background:#b91c1c;color:white;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">
                &#128274; Change Password
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- API Keys (#70) -->
    <div style="background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding:1.5rem;">
        <h2 style="margin-bottom:1.2rem;font-size:1.1rem;color:var(--primary-dark);">&#128279; API Keys</h2>
        <p style="font-size:0.85rem;color:#6b7280;margin-bottom:1rem;">Use API keys to authenticate with the REST API at <code>api.php</code>. Pass as <code>?api_key=KEY</code> or header <code>X-API-Key: KEY</code>.</p>

        <form method="POST" style="display:flex;gap:8px;margin-bottom:1rem;">
            <input type="hidden" name="action" value="generate_api_key">
            <input type="text" name="key_label" placeholder="Key label (e.g. Zapier)" style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:6px;">
            <button type="submit" style="padding:8px 16px;background:var(--primary);color:white;border:none;border-radius:6px;cursor:pointer;font-family:'Inter',sans-serif;">+ Generate</button>
        </form>

        <?php if (empty($apiKeys)): ?>
            <div style="color:#9ca3af;font-size:0.85rem;">No API keys yet.</div>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                    <th style="padding:8px;text-align:left;">Label</th>
                    <th style="padding:8px;text-align:left;">API Key</th>
                    <th style="padding:8px;text-align:left;">Created</th>
                    <th style="padding:8px;text-align:left;">Last Used</th>
                    <th style="padding:8px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apiKeys as $key): ?>
            <tr style="border-bottom:1px solid #e5e7eb;">
                <td style="padding:8px;"><?= htmlspecialchars($key['label'] ?? '') ?></td>
                <td style="padding:8px;font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars(substr($key['api_key'],0,8)) ?>…<button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($key['api_key']) ?>');this.textContent='✓ Copied!'" style="margin-left:6px;font-size:0.75rem;padding:2px 6px;background:#e5e7eb;border:none;border-radius:4px;cursor:pointer;">Copy</button></td>
                <td style="padding:8px;color:#6b7280;"><?= htmlspecialchars(substr($key['created_at'] ?? '',0,10)) ?></td>
                <td style="padding:8px;color:#6b7280;"><?= htmlspecialchars($key['last_used'] ? substr($key['last_used'],0,10) : 'Never') ?></td>
                <td style="padding:8px;">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this API key?')">
                        <input type="hidden" name="action" value="delete_api_key">
                        <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                        <button type="submit" style="color:#b91c1c;background:none;border:none;cursor:pointer;font-size:0.85rem;">&#128465; Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Quick links -->
    <div style="background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding:1.5rem;">
        <h2 style="margin-bottom:1rem;font-size:1.1rem;color:var(--primary-dark);">&#128279; Quick Links</h2>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <a href="api.php?action=ping" class="export-btn">&#128279; API Health</a>
            <a href="api.php?action=list_events" class="export-btn">&#128202; List Events API</a>
            <a href="index.php?action=export_ical" class="export-btn">&#128197; Export iCal</a>
            <a href="index.php?action=export_csv" class="export-btn">&#128196; Export CSV</a>
            <?php if (DEV_MODE): ?>
            <a href="api.php?action=generate_key&user_id=<?= (int)$currentUserId ?>&label=DevKey" class="export-btn">&#128273; Generate Dev Key</a>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>
