<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    logAudit($pdo, $_SESSION['user_id'], $_SESSION['user_type'], 'logout', 'User logged out');
}

session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
