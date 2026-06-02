<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

if (isLoggedIn()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $dept      = trim($_POST['department'] ?? '');
    $gsm       = trim($_POST['gsm'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $bankName  = trim($_POST['bank_name'] ?? '');
    $accountNo = trim($_POST['account_number'] ?? '');
    $nok       = trim($_POST['next_of_kin'] ?? '');
    $nokGsm    = trim($_POST['next_of_kin_gsm'] ?? '');
    $regDate   = $_POST['registration_date'] ?? date('Y-m-d');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    // Validation
    if (!$name)       $errors[] = 'Full name is required.';
    if (!$dept)       $errors[] = 'Department is required.';
    if (!$gsm)        $errors[] = 'GSM (phone number) is required.';
    if (!$email)      $errors[] = 'Email address is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address format.';
    if (!$bankName)   $errors[] = 'Bank name is required.';
    if (!$accountNo)  $errors[] = 'Account number is required.';
    if (!$nok)        $errors[] = 'Next of Kin name is required.';
    if (!$nokGsm)     $errors[] = 'Next of Kin GSM is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    // Check duplicate email
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM members WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'This email address is already registered.';
    }

    if (empty($errors)) {
        // Insert member with pending_secretary status (MNO assigned after director approval)
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO members
            (name, department, gsm, email, password, bank_name, account_number, next_of_kin, next_of_kin_gsm, registration_date, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,  'pending_secretary')");
        $stmt->execute([$name, $dept, $gsm, $email, $hash, $bankName, $accountNo, $nok, $nokGsm, $regDate]);
        $newMemberId = (int)$pdo->lastInsertId();

        // Notify ALL secretaries by email and system notification
        $secretaries = $pdo->query("SELECT * FROM admins WHERE role='secretary' AND is_active=1")->fetchAll();
        $memberData  = compact('name','department','gsm','email','bank_name','account_number','next_of_kin','next_of_kin_gsm');
        $memberData['department']     = $dept;
        $memberData['bank_name']      = $bankName;
        $memberData['account_number'] = $accountNo;
        $memberData['next_of_kin']    = $nok;
        $memberData['next_of_kin_gsm']= $nokGsm;

        foreach ($secretaries as $sec) {
            addNotification($pdo, $sec['id'], 'admin',
                'New Membership Application',
                "$name has submitted a membership application. Please review.",
                'info', BASE_URL . '/admin/secretary/members.php?tab=pending');

            sendMdcanEmail(
                $sec['email'], $sec['name'],
                'New Membership Application – ' . $name,
                emailNewApplicationToSecretary($memberData, $sec['name'])
            );
        }

        logAudit($pdo, $newMemberId, 'member', 'self_registered', "New application: $name ($email)");
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Member Registration | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1a3a5c 0%, #254d7a 100%); min-height: 100vh; }
        .reg-card { border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
        .reg-header { background: #1a3a5c; color: #fff; border-radius: 12px 12px 0 0; padding: 20px 25px; }
        .reg-header h4 { margin: 0; color: #fff; }
        .reg-header small { color: #c9a84c; }
        .form-label { font-weight: 600; font-size: 13px; color: #444; }
        .section-title { font-size: 12px; font-weight: 700; text-transform: uppercase;
                         color: #1a3a5c; border-bottom: 2px solid #c9a84c; padding-bottom: 4px; margin: 18px 0 12px; }
    </style>
</head>
<body class="hold-transition">
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">

        <?php if ($success): ?>
        <!-- Success Screen -->
        <div class="card reg-card text-center">
            <div class="reg-header">
                <i class="fas fa-handshake fa-2x mb-2"></i>
                <h4>Application Submitted!</h4>
                <small>MDCAN Cooperative System</small>
            </div>
            <div class="card-body py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h4 class="text-success">Thank You for Applying!</h4>
                <p class="text-muted mb-4">Your membership application has been received.<br>
                The Secretary has been notified and will review your application.</p>
                <div class="alert alert-info text-left mx-auto" style="max-width:450px">
                    <strong>What happens next?</strong>
                    <ol class="mb-0 mt-2 pl-3">
                        <li>The <strong>Secretary</strong> reviews &amp; verifies your details</li>
                        <li>Forwarded to the <strong>Director</strong> for final approval</li>
                        <li>You receive an <strong>email</strong> with the outcome &amp; your Member Number</li>
                    </ol>
                </div>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
            </div>
        </div>

        <?php else: ?>
        <!-- Registration Form -->
        <div class="card reg-card">
            <div class="reg-header d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="fas fa-user-plus mr-2"></i>New Member Registration</h4>
                    <small>MDCAN Cooperative System</small>
                </div>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-sign-in-alt mr-1"></i>Login
                </a>
            </div>
            <div class="card-body px-4 pt-3 pb-4">

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Please fix the following errors:</strong>
                    <ul class="mb-0 mt-1 pl-3">
                        <?php foreach ($errors as $e): ?>
                        <li><?= sanitize($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="regForm" novalidate>

                    <!-- Personal Information -->
                    <div class="section-title"><i class="fas fa-user mr-1"></i>Personal Information</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Aminu Bala Yabo"
                                    value="<?= sanitize($_POST['name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Department <span class="text-danger">*</span></label>
                                <input type="text" name="department" class="form-control" placeholder="e.g. Administration"
                                    value="<?= sanitize($_POST['department'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">GSM (Phone) <span class="text-danger">*</span></label>
                                <input type="tel" name="gsm" class="form-control" placeholder="e.g. 08012345678"
                                    value="<?= sanitize($_POST['gsm'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" placeholder="your@email.com"
                                    value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Registration Date</label>
                                <input type="date" name="registration_date" class="form-control"
                                    value="<?= $_POST['registration_date'] ?? date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Member Number (MNO)</label>
                                <input type="text" class="form-control bg-light" value="Auto-generated upon approval" disabled>
                                <small class="text-muted">Assigned by the Director after approval</small>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details -->
                    <div class="section-title"><i class="fas fa-university mr-1"></i>Bank Details</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                                <input type="text" name="bank_name" class="form-control" placeholder="e.g. First Bank"
                                    value="<?= sanitize($_POST['bank_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Account Number <span class="text-danger">*</span></label>
                                <input type="text" name="account_number" class="form-control" placeholder="10-digit account number"
                                    value="<?= sanitize($_POST['account_number'] ?? '') ?>" maxlength="10" required>
                            </div>
                        </div>
                    </div>

                    <!-- Next of Kin -->
                    <div class="section-title"><i class="fas fa-users mr-1"></i>Next of Kin</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Next of Kin Name <span class="text-danger">*</span></label>
                                <input type="text" name="next_of_kin" class="form-control" placeholder="Full name"
                                    value="<?= sanitize($_POST['next_of_kin'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Next of Kin GSM <span class="text-danger">*</span></label>
                                <input type="tel" name="next_of_kin_gsm" class="form-control" placeholder="e.g. 08087654321"
                                    value="<?= sanitize($_POST['next_of_kin_gsm'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Account Security -->
                    <div class="section-title"><i class="fas fa-lock mr-1"></i>Account Password</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="password" id="pwd" class="form-control" placeholder="Min. 6 characters" required>
                                    <div class="input-group-append"><span class="input-group-text" style="cursor:pointer" onclick="togglePwd('pwd','eye1')"><i class="fas fa-eye" id="eye1"></i></span></div>
                                </div>
                                <small class="text-muted">Use this password to login once approved</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="cpwd" class="form-control" placeholder="Repeat password" required>
                                    <div class="input-group-append"><span class="input-group-text" style="cursor:pointer" onclick="togglePwd('cpwd','eye2')"><i class="fas fa-eye" id="eye2"></i></span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left mr-1"></i>Back to Login
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-paper-plane mr-2"></i>Submit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function togglePwd(fieldId, iconId) {
    var f = document.getElementById(fieldId), i = document.getElementById(iconId);
    if (f.type === 'password') { f.type = 'text'; i.className = 'fas fa-eye-slash'; }
    else { f.type = 'password'; i.className = 'fas fa-eye'; }
}
// Live password match check
document.getElementById('cpwd')?.addEventListener('input', function() {
    var pwd = document.getElementById('pwd').value;
    this.style.borderColor = (this.value && this.value !== pwd) ? '#dc3545' : '';
});
</script>
</body>
</html>
