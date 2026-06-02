<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Withdrawal Approvals';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wId    = (int)($_POST['withdrawal_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($wId && in_array($action, ['approve','decline'], true)) {
        $status = $action === 'approve' ? 'approved' : 'declined';
        $stmt = $pdo->prepare("UPDATE savings_withdrawals SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='pending'");
        $stmt->execute([$status, $_SESSION['user_id'], $wId]);

        $w = $pdo->prepare("SELECT member_id, amount, withdrawal_type FROM savings_withdrawals WHERE id=?");
        $w->execute([$wId]);
        $wd = $w->fetch();
        if ($wd) {
            addNotification($pdo, $wd['member_id'], 'member', 'Withdrawal ' . ucfirst($action) . 'd',
                'Your withdrawal of ' . formatCurrency($wd['amount']) . ' has been ' . $action . 'd.',
                $action === 'approve' ? 'success' : 'danger');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', "withdrawal_$action", "Withdrawal ID: $wId");
        flashMessage('success', "Withdrawal #$wId {$action}d.");
        header('Location: ' . BASE_URL . '/admin/director/withdrawals.php');
        exit;
    }
}

$stmt = $pdo->query("SELECT w.*, m.name AS member_name, m.mno FROM savings_withdrawals w
    JOIN members m ON w.member_id = m.id ORDER BY w.requested_at DESC");
$withdrawals = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Savings Withdrawal Requests</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Type</th><th>Amount</th><th>Reason</th><th>Status</th><th>Requested</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($withdrawals)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No withdrawal requests.</td></tr>
            <?php else: foreach ($withdrawals as $i => $w): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($w['member_name']) ?></strong><br><small><?= sanitize($w['mno']) ?></small></td>
                <td><?= ucwords(str_replace('_', ' ', $w['withdrawal_type'])) ?></td>
                <td><?= formatCurrency($w['amount']) ?></td>
                <td><?= sanitize(substr($w['reason'] ?? '-', 0, 40)) ?></td>
                <td><?= statusBadge($w['status']) ?></td>
                <td><?= date('M d, Y', strtotime($w['requested_at'])) ?></td>
                <td>
                    <?php if ($w['status'] === 'pending'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                        <button name="action" value="approve" class="btn btn-xs btn-success btn-confirm" data-confirm="Approve this withdrawal?"><i class="fas fa-check"></i></button>
                        <button name="action" value="decline" class="btn btn-xs btn-danger btn-confirm" data-confirm="Decline this withdrawal?"><i class="fas fa-times"></i></button>
                    </form>
                    <?php else: echo '<span class="text-muted">-</span>'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
