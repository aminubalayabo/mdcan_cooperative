<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Members Overview';

$stmt = $pdo->query("SELECT m.*,
    (SELECT COALESCE(SUM(amount),0) FROM savings s WHERE s.member_id = m.id) AS total_savings,
    (SELECT COUNT(*) FROM loans l WHERE l.member_id = m.id AND l.status IN ('approved','disbursed','repaying')) AS active_loans
    FROM members m ORDER BY m.created_at DESC");
$members = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-users mr-2"></i>All Members</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>MNO</th><th>Name</th><th>Department</th><th>GSM</th><th>Total Savings</th><th>Active Loans</th><th>Status</th><th>Joined</th></tr>
            </thead>
            <tbody>
            <?php if (empty($members)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No members found.</td></tr>
            <?php else: foreach ($members as $i => $m): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($m['mno']) ?></strong></td>
                <td><?= sanitize($m['name']) ?></td>
                <td><?= sanitize($m['department']) ?></td>
                <td><?= sanitize($m['gsm']) ?></td>
                <td class="text-success"><?= formatCurrency($m['total_savings']) ?></td>
                <td><span class="badge badge-primary"><?= $m['active_loans'] ?></span></td>
                <td><?= statusBadge($m['status']) ?></td>
                <td><?= $m['registration_date'] ? date('M Y', strtotime($m['registration_date'])) : '-' ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
