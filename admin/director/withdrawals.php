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
        $stmt = $pdo->prepare("UPDATE savings_withdrawals SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='under_review'");
        $stmt->execute([$status, $_SESSION['user_id'], $wId]);

        $w = $pdo->prepare("SELECT member_id, amount, withdrawal_type FROM savings_withdrawals WHERE id=?");
        $w->execute([$wId]);
        $wd = $w->fetch();
        if ($wd) {
            if ($action === 'approve') {
                // Immediately deduct from savings ledger on approval
                $desc = ucwords(str_replace('_', ' ', $wd['withdrawal_type'])) . " — Withdrawal #$wId approved";
                $pdo->prepare("INSERT INTO savings (member_id, amount, type, month_year, description, recorded_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$wd['member_id'], -$wd['amount'], 'withdrawal', date('Y-m'), $desc, $_SESSION['user_id']]);

                addNotification($pdo, $wd['member_id'], 'member', 'Withdrawal Approved',
                    'Your withdrawal of ' . formatCurrency($wd['amount']) . ' has been approved and deducted from your savings balance.',
                    'success');

                $secs = $pdo->query("SELECT id FROM admins WHERE role='secretary' AND is_active=1")->fetchAll();
                foreach ($secs as $sec) {
                    addNotification($pdo, $sec['id'], 'admin', 'Withdrawal Approved — Process Required',
                        ucwords(str_replace('_', ' ', $wd['withdrawal_type'])) . ' of ' . formatCurrency($wd['amount']) . ' approved. Please process payment.',
                        'success', BASE_URL . '/admin/secretary/withdrawals.php?filter=approved');
                }
            } else {
                addNotification($pdo, $wd['member_id'], 'member', 'Withdrawal Declined',
                    'Your withdrawal of ' . formatCurrency($wd['amount']) . ' has been declined.',
                    'danger');
            }
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', "withdrawal_$action", "Withdrawal ID: $wId");
        flashMessage('success', "Withdrawal #$wId {$action}d.");
        header('Location: ' . BASE_URL . '/admin/director/withdrawals.php?filter=' . ($action === 'approve' ? 'approved' : 'declined'));
        exit;
    }
}

$filter = $_GET['filter'] ?? 'under_review';
$validFilters = ['under_review', 'approved', 'declined', 'processed', 'all'];
if (!in_array($filter, $validFilters)) $filter = 'under_review';

$where  = $filter === 'all' ? '' : 'WHERE w.status = ?';
$params = $filter === 'all' ? [] : [$filter];

$stmt = $pdo->prepare("SELECT w.*, m.name AS member_name, m.mno, m.bank_name, m.account_number,
    a.name AS reviewed_by_name FROM savings_withdrawals w
    JOIN members m ON w.member_id = m.id
    LEFT JOIN admins a ON w.reviewed_by = a.id
    $where ORDER BY w.requested_at DESC");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filter Tabs -->
<div class="card mb-0">
    <div class="card-header p-2">
        <ul class="nav nav-pills flex-wrap">
            <?php foreach (['under_review' => 'Under Review', 'approved' => 'Approved', 'declined' => 'Declined', 'processed' => 'Processed', 'all' => 'All'] as $f => $lbl): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="card mt-0">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Savings Withdrawal Requests</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Bank Details</th><th>Type</th><th>Amount</th><th>Reason / Notes</th><th>Status</th><th>Requested</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($withdrawals)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No withdrawal requests.</td></tr>
            <?php else: foreach ($withdrawals as $i => $w): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($w['member_name']) ?></strong><br><small><?= sanitize($w['mno']) ?></small></td>
                <td><small><?= sanitize($w['bank_name']) ?><br><?= sanitize($w['account_number']) ?></small></td>
                <td><?= ucwords(str_replace('_', ' ', $w['withdrawal_type'])) ?></td>
                <td><?= formatCurrency($w['amount']) ?></td>
                <td>
                    <small><?= sanitize(substr($w['reason'] ?? '-', 0, 40)) ?></small>
                    <?php if (!empty($w['review_notes'])): ?>
                    <br><small class="text-warning"><i class="fas fa-sticky-note"></i> <?= sanitize($w['review_notes']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($w['status']) ?></td>
                <td><?= date('M d, Y', strtotime($w['requested_at'])) ?></td>
                <td>
                    <?php if ($w['status'] === 'under_review'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                        <button name="action" value="approve" class="btn btn-xs btn-success btn-confirm" data-confirm="Approve this withdrawal?"><i class="fas fa-check"></i> Approve</button>
                        <button name="action" value="decline" class="btn btn-xs btn-danger btn-confirm" data-confirm="Decline this withdrawal?"><i class="fas fa-times"></i> Decline</button>
                    </form>
                    <?php else: echo '<span class="text-muted small">—</span>'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
