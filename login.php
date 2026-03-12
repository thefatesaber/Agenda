<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

session_start();

// Auto-migrate remember_token on users table
$chkRT = $connection->query("SHOW COLUMNS FROM users LIKE 'remember_token'");
if ($chkRT && $chkRT->num_rows === 0) {
    $connection->query("ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL");
}

// Check remember_me cookie
if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt  = $connection->prepare("SELECT id, username FROM users WHERE remember_token = ? LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($uid, $uname);
    $stmt->fetch();
    $stmt->close();
    if ($uid) {
        $_SESSION['user_id']  = $uid;
        $_SESSION['username'] = $uname;
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
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if ($username && $password) {
        $stmt = $connection->prepare("SELECT id, password_hash FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($uid, $hash);
        $stmt->fetch();
        $stmt->close();

        if ($uid && password_verify($password, $hash)) {
            $_SESSION['user_id']  = $uid;
            $_SESSION['username'] = $username;

            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $stmt2 = $connection->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                $stmt2->bind_param('si', $token, $uid);
                $stmt2->execute();
                $stmt2->close();
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

            <div style="margin-top:0.75rem;">
                <label style="font-weight:normal;display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="remember"> Remember me for 30 days
                </label>
            </div>

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
