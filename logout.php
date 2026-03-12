<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection.php';

session_start();

// Clear remember token in DB
if (!empty($_SESSION['user_id'])) {
    $uid   = (int)$_SESSION['user_id'];
    $null  = null;
    $stmt  = $connection->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();
}

// Delete cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

session_unset();
session_destroy();
header('Location: login.php');
exit;
