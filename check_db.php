<?php
/**
 * MDCAN Database Diagnostic Script
 * Visit: http://localhost/mdcan_cooperative/check_db.php
 * DELETE this file after fixing the issues.
 */
require_once __DIR__ . '/config/db.php';

$required = ['rejection_reason', 'forwarded_by', 'forwarded_at'];
$cols = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN);
$members = $pdo->query("SELECT id, name, email, status, mno FROM members ORDER BY created_at DESC LIMIT 20")->fetchAll();
$enumRow = $pdo->query("SHOW COLUMNS FROM members LIKE 'status'")->fetch();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<title>DB Check</title></head>
<body class="p-4 bg-light">
<div class="container">
    <h3>MDCAN Database Diagnostic</h3>

    <div class="card mb-3">
        <div class="card-header bg-primary text-white">Required Columns in <code>members</code> table</div>
        <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th>Column</th><th>Exists?</th><th>Action needed</th></tr></thead>
            <tbody>
            <?php foreach ($required as $col): $exists = in_array($col, $cols); ?>
            <tr class="<?= $exists ? 'table-success' : 'table-danger' ?>">
                <td><code><?= $col ?></code></td>
                <td><?= $exists ? '✅ YES' : '❌ NO' ?></td>
                <td><?= $exists ? 'OK' : 'Run migration SQL below' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-info text-white">Status ENUM values</div>
        <div class="card-body">
            <code><?= htmlspecialchars($enumRow['Type'] ?? 'not found') ?></code><br><br>
            <?php
            $needed = ['pending_secretary','pending_director','rejected'];
            foreach ($needed as $v):
                $has = str_contains($enumRow['Type'] ?? '', "'$v'");
            ?>
            <span class="badge badge-<?= $has ? 'success' : 'danger' ?> mr-1"><?= $v ?>: <?= $has ? '✅' : '❌ MISSING' ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Recent Members</div>
        <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>MNO</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
            <tr>
                <td><?= $m['id'] ?></td>
                <td><?= htmlspecialchars($m['name']) ?></td>
                <td><?= htmlspecialchars($m['email']) ?></td>
                <td><strong><?= htmlspecialchars($m['status']) ?></strong></td>
                <td><?= htmlspecialchars($m['mno'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php
    $missing = array_filter($required, fn($c) => !in_array($c, $cols));
    $enumOk = str_contains($enumRow['Type'] ?? '', "'pending_director'");
    if ($missing || !$enumOk):
    ?>
    <div class="card border-danger">
        <div class="card-header bg-danger text-white">Fix SQL — Run these one at a time in phpMyAdmin</div>
        <div class="card-body">
            <p>Go to <strong>phpMyAdmin → mdcan_cooperative → SQL tab</strong>.<br>
            Copy and run <strong>each block separately</strong>. If you get "Duplicate column name", skip that one.</p>
            <?php if (!$enumOk): ?>
            <p><strong>Step 1 – Fix status ENUM:</strong></p>
            <pre class="bg-dark text-white p-3 rounded">ALTER TABLE members
    MODIFY COLUMN status ENUM(
        'pending_secretary',
        'pending_director',
        'active',
        'inactive',
        'suspended',
        'rejected'
    ) NOT NULL DEFAULT 'pending_secretary';</pre>
            <?php endif; ?>
            <?php if (in_array('rejection_reason', $missing)): ?>
            <p><strong>Step – Add rejection_reason column:</strong></p>
            <pre class="bg-dark text-white p-3 rounded">ALTER TABLE members ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER status;</pre>
            <?php endif; ?>
            <?php if (in_array('forwarded_by', $missing)): ?>
            <p><strong>Step – Add forwarded_by column:</strong></p>
            <pre class="bg-dark text-white p-3 rounded">ALTER TABLE members ADD COLUMN forwarded_by INT DEFAULT NULL AFTER rejection_reason;</pre>
            <?php endif; ?>
            <?php if (in_array('forwarded_at', $missing)): ?>
            <p><strong>Step – Add forwarded_at column:</strong></p>
            <pre class="bg-dark text-white p-3 rounded">ALTER TABLE members ADD COLUMN forwarded_at TIMESTAMP NULL DEFAULT NULL AFTER forwarded_by;</pre>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success"><strong>✅ All columns and ENUM values are correct!</strong> Delete this file and test the workflow again.</div>
    <?php endif; ?>

</div></body></html>
