<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

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
            $_SESSION['user_id']    = $admin['id'];
            $_SESSION['user_type']  = 'admin';
            $_SESSION['user_role']  = $admin['role'];
            $_SESSION['user_name']  = $admin['name'];
            $_SESSION['user_email'] = $admin['email'];
            logAudit($pdo, $admin['id'], 'admin', 'login', 'Admin logged in');
            header('Location: ' . BASE_URL . '/admin/' . $admin['role'] . '/dashboard.php');
            exit;
        }

        // Check members (only active members can login)
        $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ?");
        $stmt->execute([$email]);
        $member = $stmt->fetch();

        if ($member) {
            if (!password_verify($password, $member['password'])) {
                $error = 'Invalid email or password. Please try again.';
            } elseif ($member['status'] === 'pending_secretary') {
                $error = 'Your application is under review by the Secretary. You will be notified by email once it progresses.';
            } elseif ($member['status'] === 'pending_director') {
                $error = 'Your application has been forwarded to the Director for final approval. Please wait for an email notification.';
            } elseif ($member['status'] === 'rejected') {
                $reason = $member['rejection_reason'] ?? 'No reason provided.';
                $error = 'Your membership application was not approved. Reason: ' . sanitize($reason);
            } elseif ($member['status'] === 'suspended') {
                $error = 'Your account has been suspended. Please contact the Cooperative Secretary.';
            } elseif ($member['status'] === 'inactive') {
                $error = 'Your account is inactive. Please contact the Cooperative Secretary.';
            } else {
                // Active member — login
                session_regenerate_id(true);
                $_SESSION['user_id']    = $member['id'];
                $_SESSION['user_type']  = 'member';
                $_SESSION['user_role']  = 'member';
                $_SESSION['user_name']  = $member['name'];
                $_SESSION['user_email'] = $member['email'];
                $_SESSION['user_mno']   = $member['mno'];
                logAudit($pdo, $member['id'], 'member', 'login', 'Member logged in');
                header('Location: ' . BASE_URL . '/member/dashboard.php');
                exit;
            }
        } elseif (!$error) {
            $error = 'Invalid email or password. Please try again.';
        }
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
        body { background: linear-gradient(135deg, #1a3a5c 0%, #254d7a 100%); min-height: 100vh; }
        .login-card { border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,.3); overflow: hidden; }
        .login-header { background: #1a3a5c; color: #fff; text-align: center; padding: 28px 25px 22px; }
        .login-header .logo-text { font-size: 1.8rem; font-weight: 700; color: #fff; }
        .login-header .logo-text span { color: #c9a84c; }
        .divider-text { text-align: center; position: relative; margin: 20px 0; }
        .divider-text::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #ddd; }
        .divider-text span { background: #fff; padding: 0 12px; position: relative; color: #999; font-size: 13px; }
        .register-panel { background: #f8fffe; border-top: 1px solid #e8f5e9; padding: 20px 25px 25px; text-align: center; }
        .register-panel h6 { color: #1a3a5c; font-weight: 700; margin-bottom: 6px; }
        .register-panel p { color: #666; font-size: 13px; margin-bottom: 14px; }
        .btn-register { background: linear-gradient(135deg, #28a745, #20c997); color: #fff !important;
                        border: none; border-radius: 8px; padding: 11px 20px; font-weight: 600;
                        display: block; width: 100%; font-size: 15px; text-decoration: none; }
        .btn-register:hover { background: linear-gradient(135deg, #218838, #17a589); transform: translateY(-1px); }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    </style>
</head>
<body class="hold-transition">
<div class="container d-flex align-items-center justify-content-center" style="min-height:100vh; padding: 20px 0;">
    <div class="col-md-9 col-lg-7 col-xl-6">
        <div class="row">

            <!-- ── LEFT: Login Form ── -->
            <div class="col-md-6 pr-md-0">
                <div class="card login-card h-100" style="border-radius: 12px 0 0 12px !important">
                    <div class="login-header">
                        <div class="logo-text"><i class="fas fa-handshake mr-2"></i>MDCAN <span>Coop</span></div>
                        <p class="mb-0 mt-1" style="color:rgba(255,255,255,.7);font-size:13px">Cooperative Management System</p>
                    </div>
                    <div class="card-body px-4 pt-4">
                        <h6 class="font-weight-bold text-muted mb-3"><i class="fas fa-sign-in-alt mr-2"></i>Member / Staff Login</h6>

                        <?php if ($error): ?>
                        <div class="alert alert-danger alert-sm py-2 small">
                            <i class="fas fa-exclamation-circle mr-1"></i><?= $error ?>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
                        <div class="alert alert-warning py-2 small">
                            <i class="fas fa-lock mr-1"></i>Access denied. Please login with correct credentials.
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="form-group mb-3">
                                <label class="small font-weight-bold">Email Address</label>
                                <div class="input-group input-group-sm">
                                    <input type="email" name="email" class="form-control"
                                        placeholder="your@email.com"
                                        value="<?= sanitize($_POST['email'] ?? '') ?>" required autofocus>
                                    <div class="input-group-append"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                                </div>
                            </div>
                            <div class="form-group mb-3">
                                <label class="small font-weight-bold">Password</label>
                                <div class="input-group input-group-sm">
                                    <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                                    <div class="input-group-append">
                                        <span class="input-group-text" style="cursor:pointer" onclick="togglePwd()">
                                            <i class="fas fa-eye" id="eyeIcon"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-block btn-sm" style="background:#1a3a5c;color:#fff;padding:9px">
                                <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                            </button>
                        </form>

                        <div class="mt-3 small text-muted text-center">
                            <details>
                                <summary style="cursor:pointer">Demo credentials</summary>
                                <div class="mt-2 text-left">
                                    Director: director@mdcan.edu.ng<br>
                                    Secretary: secretary@mdcan.edu.ng<br>
                                    Member: member@mdcan.edu.ng<br>
                                    Password: <strong>mdcan2024</strong>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── RIGHT: New Member Panel ── -->
            <div class="col-md-6 pl-md-0">
                <div class="card login-card h-100" style="border-radius: 0 12px 12px 0 !important; border-left: 1px solid #e9ecef;">
                    <div class="card-body d-flex flex-column justify-content-center px-4 py-4">

                        <div class="text-center mb-3">
                            <div style="width:64px;height:64px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px">
                                <i class="fas fa-user-plus fa-2x text-white"></i>
                            </div>
                            <h5 class="font-weight-bold text-dark mb-1">New Member?</h5>
                            <p class="text-muted small">Join the MDCAN Cooperative today. Fill the membership form and your application will be reviewed.</p>
                        </div>

                        <a href="<?= BASE_URL ?>/auth/register.php" class="btn-register mb-3">
                            <i class="fas fa-clipboard-list mr-2"></i>Apply for Membership
                        </a>

                        <hr class="my-3">

                        <h6 class="font-weight-bold small text-center text-muted mb-3">How it works</h6>
                        <div class="d-flex align-items-start mb-2">
                            <span class="badge badge-primary mr-2 mt-1" style="min-width:22px">1</span>
                            <small class="text-muted">Fill and submit the membership form</small>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <span class="badge badge-warning mr-2 mt-1" style="min-width:22px">2</span>
                            <small class="text-muted">Secretary reviews &amp; verifies your details</small>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <span class="badge badge-info mr-2 mt-1" style="min-width:22px">3</span>
                            <small class="text-muted">Director gives final approval</small>
                        </div>
                        <div class="d-flex align-items-start">
                            <span class="badge badge-success mr-2 mt-1" style="min-width:22px">4</span>
                            <small class="text-muted">You receive your Member Number (MNO) by email</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function togglePwd() {
    var p = document.getElementById('password'), i = document.getElementById('eyeIcon');
    if (p.type === 'password') { p.type = 'text'; i.className = 'fas fa-eye-slash'; }
    else { p.type = 'password'; i.className = 'fas fa-eye'; }
}
</script>
</body>
</html>
