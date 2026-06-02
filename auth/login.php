<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in — redirect to correct dashboard
if (isLoggedIn()) {
    if (isDirector())  { header('Location: ' . BASE_URL . '/admin/director/dashboard.php');  exit; }
    if (isSecretary()) { header('Location: ' . BASE_URL . '/admin/secretary/dashboard.php'); exit; }
    header('Location: ' . BASE_URL . '/member/dashboard.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        // Check admins first
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $admin['id'];
            $_SESSION['user_type'] = 'admin';
            $_SESSION['user_role'] = $admin['role'];
            $_SESSION['user_name'] = $admin['name'];
            $_SESSION['user_email'] = $admin['email'];

            logAudit($pdo, $admin['id'], 'admin', 'login', 'Admin logged in');

            if ($admin['role'] === 'director') {
                header('Location: ' . BASE_URL . '/admin/director/dashboard.php');
            } else {
                header('Location: ' . BASE_URL . '/admin/secretary/dashboard.php');
            }
            exit;
        }

        // Check members
        $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $member = $stmt->fetch();

        if ($member && password_verify($password, $member['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $member['id'];
            $_SESSION['user_type'] = 'member';
            $_SESSION['user_role'] = 'member';
            $_SESSION['user_name'] = $member['name'];
            $_SESSION['user_email'] = $member['email'];
            $_SESSION['user_mno']  = $member['mno'];

            logAudit($pdo, $member['id'], 'member', 'login', 'Member logged in');

            header('Location: ' . BASE_URL . '/member/dashboard.php');
            exit;
        }

        $error = 'Invalid email or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1a3a5c 0%, #254d7a 100%); }
        .login-card { border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
        .login-logo { color: #fff; font-size: 1.8rem; font-weight: 700; }
        .login-logo span { color: #c9a84c; }
        .card-header { background: #1a3a5c; color: #fff; text-align: center; border-radius: 12px 12px 0 0 !important; padding: 25px; }
    </style>
</head>
<body class="hold-transition">
<div class="container d-flex align-items-center justify-content-center" style="min-height:100vh">
    <div class="col-md-5 col-lg-4">
        <div class="card login-card">
            <div class="card-header">
                <div class="login-logo mb-2">
                    <i class="fas fa-handshake mr-2"></i>MDCAN <span>Coop</span>
                </div>
                <p class="mb-0 small opacity-75">Cooperative Management System</p>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i><?= sanitize($error) ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
                <div class="alert alert-warning"><i class="fas fa-lock mr-2"></i>Access denied. Please login with appropriate credentials.</div>
                <?php endif; ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Email Address</label>
                        <div class="input-group">
                            <input type="email" name="email" class="form-control" placeholder="your@email.com" value="<?= sanitize($_POST['email'] ?? '') ?>" required autofocus>
                            <div class="input-group-append"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                            <div class="input-group-append">
                                <span class="input-group-text" style="cursor:pointer" onclick="togglePwd()"><i class="fas fa-eye" id="eye-icon"></i></span>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-block" style="background:#1a3a5c;color:#fff">
                        <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                    </button>
                </form>
                <hr>
                <div class="text-center small text-muted">
                    <strong>Demo Credentials</strong><br>
                    Director: director@mdcan.edu.ng<br>
                    Secretary: secretary@mdcan.edu.ng<br>
                    Member: member@mdcan.edu.ng<br>
                    Password: <strong>mdcan2024</strong>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function togglePwd() {
    var p = document.getElementById('password');
    var i = document.getElementById('eye-icon');
    if (p.type === 'password') { p.type = 'text'; i.classList.replace('fa-eye','fa-eye-slash'); }
    else { p.type = 'password'; i.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
