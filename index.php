<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

if (isDirector()) {
    header('Location: ' . BASE_URL . '/admin/director/dashboard.php');
} elseif (isSecretary()) {
    header('Location: ' . BASE_URL . '/admin/secretary/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/member/dashboard.php');
}
exit;
