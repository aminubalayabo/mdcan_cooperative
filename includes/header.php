<?php
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/db.php';
}
require_once __DIR__ . '/functions.php';

$unreadCount = 0;
if (isLoggedIn()) {
    $unreadCount = countUnread($pdo, $_SESSION['user_id'], $_SESSION['user_type']);
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? sanitize($pageTitle) . ' | ' : '' ?><?= APP_NAME ?></title>
    <!-- AdminLTE & Bootstrap -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="<?= BASE_URL ?>" class="nav-link"><?= APP_NAME ?></a>
        </li>
    </ul>
    <ul class="navbar-nav ml-auto">
        <!-- Notifications -->
        <li class="nav-item dropdown">
            <a class="nav-link" data-toggle="dropdown" href="#">
                <i class="far fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                <span class="badge badge-warning navbar-badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                <span class="dropdown-header"><?= $unreadCount ?> Notification<?= $unreadCount !== 1 ? 's' : '' ?></span>
                <div class="dropdown-divider"></div>
                <?php
                if (isLoggedIn()) {
                    $notifs = getUnreadNotifications($pdo, $_SESSION['user_id'], $_SESSION['user_type']);
                    foreach ($notifs as $n):
                ?>
                <a href="<?= $n['link'] ?: '#' ?>" class="dropdown-item">
                    <i class="fas fa-info-circle mr-2 text-<?= $n['type'] ?>"></i>
                    <div class="media-body">
                        <p class="text-sm mb-0"><?= sanitize($n['message']) ?></p>
                        <p class="text-sm text-muted mb-0"><i class="far fa-clock mr-1"></i><?= timeAgo($n['created_at']) ?></p>
                    </div>
                </a>
                <?php endforeach; }
                if (empty($notifs ?? [])): ?>
                <span class="dropdown-item text-muted">No new notifications</span>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item dropdown-footer">See All Notifications</a>
            </div>
        </li>
        <!-- User menu -->
        <li class="nav-item dropdown">
            <a class="nav-link" data-toggle="dropdown" href="#">
                <i class="fas fa-user-circle mr-1"></i>
                <?= sanitize($_SESSION['user_name'] ?? 'User') ?>
            </a>
            <div class="dropdown-menu dropdown-menu-right">
                <span class="dropdown-header">
                    <span class="badge badge-primary"><?= ucfirst($_SESSION['user_role'] ?? '') ?></span>
                </span>
                <div class="dropdown-divider"></div>
                <a href="<?= BASE_URL ?>/auth/logout.php" class="dropdown-item">
                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                </a>
            </div>
        </li>
    </ul>
</nav>
<!-- /Navbar -->

<!-- Sidebar -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="<?= BASE_URL ?>" class="brand-link">
        <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="MDCAN" class="brand-image img-circle elevation-3" style="opacity:.8" onerror="this.style.display='none'">
        <span class="brand-text font-weight-light">MDCAN Coop</span>
    </a>
    <div class="sidebar">
        <div class="user-panel mt-3 pb-3 mb-3 d-flex">
            <div class="image">
                <i class="fas fa-user-circle fa-2x text-white ml-2 mt-1"></i>
            </div>
            <div class="info">
                <a href="#" class="d-block"><?= sanitize($_SESSION['user_name'] ?? 'User') ?></a>
                <small class="text-muted"><?= ucfirst($_SESSION['user_role'] ?? '') ?></small>
            </div>
        </div>
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">

            <?php if (isMember()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/member/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/member/loans.php" class="nav-link <?= $currentPage === 'loans.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-hand-holding-usd"></i><p>My Loans</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/member/savings.php" class="nav-link <?= $currentPage === 'savings.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-piggy-bank"></i><p>My Savings</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/member/withdrawals.php" class="nav-link <?= $currentPage === 'withdrawals.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-money-bill-wave"></i><p>Withdrawals</p>
                    </a>
                </li>

            <?php elseif (isSecretary()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/secretary/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/secretary/members.php" class="nav-link <?= $currentPage === 'members.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-users"></i><p>Members</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/secretary/loans.php" class="nav-link <?= $currentPage === 'loans.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-file-invoice-dollar"></i><p>Loans</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/secretary/savings.php" class="nav-link <?= $currentPage === 'savings.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-piggy-bank"></i><p>Savings</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/secretary/withdrawals.php" class="nav-link <?= $currentPage === 'withdrawals.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-money-check-alt"></i><p>Withdrawals</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/secretary/payroll.php" class="nav-link <?= $currentPage === 'payroll.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-file-export"></i><p>Payroll Export</p>
                    </a>
                </li>

            <?php elseif (isDirector()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/director/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/director/members.php" class="nav-link <?= $currentPage === 'members.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-users"></i><p>Members</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/director/loans.php" class="nav-link <?= $currentPage === 'loans.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-file-invoice-dollar"></i><p>Loan Approvals</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/director/withdrawals.php" class="nav-link <?= $currentPage === 'withdrawals.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-money-check-alt"></i><p>Withdrawals</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/director/reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-chart-bar"></i><p>Reports</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/admin/director/audit_logs.php" class="nav-link <?= $currentPage === 'audit_logs.php' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-history"></i><p>Audit Logs</p>
                    </a>
                </li>
            <?php endif; ?>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/auth/logout.php" class="nav-link text-danger">
                        <i class="nav-icon fas fa-sign-out-alt"></i><p>Logout</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>
<!-- /Sidebar -->

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><?= isset($pageTitle) ? sanitize($pageTitle) : APP_NAME ?></h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Home</a></li>
                        <?php if (isset($pageTitle)): ?>
                        <li class="breadcrumb-item active"><?= sanitize($pageTitle) ?></li>
                        <?php endif; ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <section class="content">
        <div class="container-fluid">
            <?= renderFlash() ?>
