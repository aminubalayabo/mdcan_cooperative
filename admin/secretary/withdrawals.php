<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Savings Withdrawals';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wId    = (int)($_POST['withdrawal_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($wId && $action === 'process') {
        $pdo->prepare("UPDATE savings_withdrawals SET status='processed', processed_at=NOW() WHERE id=? AND status='approved'")
            ->execute([$wId]);

        $w = $pdo->prepare("SELECT member_id, amount FROM savings_withdrawals WHERE id=?");
        $w->execute([$wId]);
        $wd = $w->fetch();
        if ($wd) {
            addNotification($pdo, $wd['member_id'], 'member', 'Withdrawal Processed',
                'Your withdrawal of ' . formatCurrency($wd['amount']) . ' has been processed.', 'success');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'withdrawal_processed', "ID: $wId");
        flashMessage('success', "Withdrawal #$wId marked as processed.");
        header('Location: ' . BASE_URL . '/admin/secretary/withdrawals.php');
        exit;
    }
}

$stmt = $pdo->query("SELECT w.*, m.name AS member_name, m.mno, m.bank_name, m.account_number,
    a.name AS approved_by_name FROM savings_withdrawals w
    JOIN members m ON w.member_id = m.id
    LEFT JOIN admins a ON w.approved_by = a.id
    ORDER BY w.requested_at DESC");
$withdrawals = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Withdrawal Requests</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Bank Details</th><th>Type</th><th>Amount</th><th>Status</th><th>Requested</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($withdrawals)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No withdrawals.</td></tr>
            <?php else: foreach ($withdrawals as $i => $w): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($w['member_name']) ?></strong><br><small><?= sanitize($w['mno']) ?></small></td>
                <td><small><?= sanitize($w['bank_name']) ?><br><?= sanitize($w['account_number']) ?></small></td>
                <td><small><?= ucwords(str_replace('_', ' ', $w['withdrawal_type'])) ?></small></td>
                <td><?= formatCurrency($w['amount']) ?></td>
                <td><?= statusBadge($w['status']) ?></td>
                <td><small><?= date('M d, Y', strtotime($w['requested_at'])) ?></small></td>
                <td>
                    <?php if ($w['status'] === 'approved'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                        <button name="action" value="process" class="btn btn-xs btn-primary btn-confirm" data-confirm="Mark as processed?">
                            <i class="fas fa-check-double"></i> Process
                        </button>
                    </form>
                    <?php else: echo '<span class="text-muted small">-</span>'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
