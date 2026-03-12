<?php
session_start();

if (!defined('REQUIRE_AUTH')) {
    require_once __DIR__ . '/config.php';
}

if (REQUIRE_AUTH) {
    if (empty($_SESSION['user_id'])) {
        // Check if any users exist
        $checkUsers = $connection->query("SELECT COUNT(*) AS cnt FROM users");
        $userCount = 0;
        if ($checkUsers) {
            $row = $checkUsers->fetch_assoc();
            $userCount = (int)$row['cnt'];
        }
        if ($userCount === 0) {
            header('Location: register.php');
        } else {
            header('Location: login.php');
        }
        exit;
    }
}
