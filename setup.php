<?php
/**
 * MDCAN Cooperative - One-time setup / password reset script.
 * Run this ONCE at: http://localhost/mdcan_cooperative/setup.php
 * DELETE or rename this file after use.
 */
require_once __DIR__ . '/config/db.php';

$password = 'mdcan2024';
$hash     = password_hash($password, PASSWORD_BCRYPT);

// Update or insert admin accounts
$admins = [
    ['MDCAN Director',   'director@mdcan.edu.ng',   'director'],
    ['MDCAN Secretary',  'secretary@mdcan.edu.ng',  'secretary'],
];

foreach ($admins as [$name, $email, $role]) {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("UPDATE admins SET password = ? WHERE email = ?")
            ->execute([$hash, $email]);
    } else {
        $pdo->prepare("INSERT INTO admins (name, email, password, role) VALUES (?,?,?,?)")
            ->execute([$name, $email, $hash, $role]);
    }
}

// Update or insert demo member
$stmt = $pdo->prepare("SELECT id FROM members WHERE email = 'member@mdcan.edu.ng'");
$stmt->execute();
if ($stmt->fetchColumn()) {
    $pdo->prepare("UPDATE members SET password = ? WHERE email = 'member@mdcan.edu.ng'")
        ->execute([$hash]);
} else {
    $pdo->prepare("INSERT INTO members (mno, name, department, gsm, email, password, bank_name, account_number, next_of_kin, next_of_kin_gsm, registration_date, status)
        VALUES ('MNO-0001','Demo Member','Administration','08011111111','member@mdcan.edu.ng',?,
                'First Bank','1234567890','Demo Next of Kin','08022222222',CURDATE(),'active')")
        ->execute([$hash]);

    // Create shares entry for demo member
    $memberId = $pdo->lastInsertId();
    $pdo->prepare("INSERT IGNORE INTO member_shares (member_id) VALUES (?)")->execute([$memberId]);
}

// Verify
$ok = password_verify($password, $hash);
?>
<!DOCTYPE html>
<html>
<head>
    <title>MDCAN Setup</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card col-md-6 mx-auto shadow">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0">&#10003; MDCAN Setup Complete</h4>
        </div>
        <div class="card-body">
            <p class="mb-1">Accounts created/updated with password: <strong>mdcan2024</strong></p>
            <hr>
            <table class="table table-sm table-bordered">
                <thead class="thead-light"><tr><th>Role</th><th>Email</th><th>Password</th></tr></thead>
                <tbody>
                    <tr><td>Director</td><td>director@mdcan.edu.ng</td><td>mdcan2024</td></tr>
                    <tr><td>Secretary</td><td>secretary@mdcan.edu.ng</td><td>mdcan2024</td></tr>
                    <tr><td>Member</td><td>member@mdcan.edu.ng</td><td>mdcan2024</td></tr>
                </tbody>
            </table>
            <div class="alert alert-warning mb-3">
                <strong>Important:</strong> Delete or rename <code>setup.php</code> after logging in.
            </div>
            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-block">Go to Login</a>
        </div>
    </div>
</div>
</body>
</html>
