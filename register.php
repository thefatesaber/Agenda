<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

session_start();

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Ensure users table exists
$connection->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$username || !$password || !$confirm) {
        $error = 'All fields are required.';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check duplicate
        $stmt = $connection->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = 'Username already taken.';
        }
        $stmt->close();

        if (!$error) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $connection->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->bind_param('ss', $username, $hash);
            $stmt->execute();
            $newId = $connection->insert_id;
            $stmt->close();

            $_SESSION['user_id'] = $newId;
            $_SESSION['username'] = $username;
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – Calendar</title>
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
        <h2>Create Account</h2>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" class="auth-form">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autocomplete="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">

            <label for="confirm">Confirm Password</label>
            <input type="password" id="confirm" name="confirm" required autocomplete="new-password">

            <button type="submit" class="auth-btn">Create Account</button>
        </form>
        <p style="text-align:center; margin-top:1rem;">
            Already have an account? <a href="login.php" class="auth-link">Sign in</a>
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
