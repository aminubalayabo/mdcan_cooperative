<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Withdrawal Requests';

// ── Forward withdrawal to Director (as-is or reviewed) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_withdrawal'])) {
    $wId            = (int)($_POST['withdrawal_id'] ?? 0);
    $adjustedAmount = (float)($_POST['adjusted_amount'] ?? 0);
    $reviewNotes    = trim($_POST['review_notes'] ?? '');

    if ($wId) {
        if ($adjustedAmount > 0) {
            $pdo->prepare("UPDATE savings_withdrawals SET status='under_review', reviewed_by=?, reviewed_at=NOW(), amount=?, review_notes=? WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $adjustedAmount, $reviewNotes ?: null, $wId]);
        } else {
            $pdo->prepare("UPDATE savings_withdrawals SET status='under_review', reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $reviewNotes ?: null, $wId]);
        }

        $wRow = $pdo->prepare("SELECT w.amount, w.withdrawal_type, m.name AS mname FROM savings_withdrawals w JOIN members m ON w.member_id=m.id WHERE w.id=?");
        $wRow->execute([$wId]);
        $wr = $wRow->fetch();

        if ($wr) {
            $adjStr = $adjustedAmount > 0 ? ' (Amount adjusted to ' . formatCurrency($adjustedAmount) . ')' : '';
            logAudit($pdo, $_SESSION['user_id'], 'admin', 'withdrawal_forwarded',
                "Withdrawal #$wId forwarded$adjStr" . ($reviewNotes ? ". Note: $reviewNotes" : ''));
            $directors = $pdo->query("SELECT * FROM admins WHERE role='director' AND is_active=1")->fetchAll();
            foreach ($directors as $dir) {
                addNotification($pdo, $dir['id'], 'admin', 'Withdrawal Awaiting Approval',
                    $wr['mname'] . "'s " . ucwords(str_replace('_', ' ', $wr['withdrawal_type'])) . ' of ' . formatCurrency($wr['amount']) . ' awaiting your approval.' . ($adjustedAmount > 0 ? ' (Secretary adjusted)' : ''),
                    'warning', BASE_URL . '/admin/director/withdrawals.php');
            }
        }

        $adjMsg = $adjustedAmount > 0 ? ' Amount adjusted to ' . formatCurrency($adjustedAmount) . '.' : '';
        flashMessage('success', "Withdrawal #$wId forwarded to Director.$adjMsg");
        header('Location: ' . BASE_URL . '/admin/secretary/withdrawals.php?filter=under_review');
        exit;
    }
}

// ── Mark approved withdrawal as processed ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process') {
    $wId = (int)($_POST['withdrawal_id'] ?? 0);
    if ($wId) {
        $pdo->prepare("UPDATE savings_withdrawals SET status='processed', processed_at=NOW() WHERE id=? AND status='approved'")
            ->execute([$wId]);

        $w = $pdo->prepare("SELECT member_id, amount FROM savings_withdrawals WHERE id=?");
        $w->execute([$wId]);
        $wd = $w->fetch();
        if ($wd) {
            addNotification($pdo, $wd['member_id'], 'member', 'Withdrawal Payment Processed',
                'Your withdrawal payment of ' . formatCurrency($wd['amount']) . ' has been disbursed.', 'success');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'withdrawal_processed', "ID: $wId");
        flashMessage('success', "Withdrawal #$wId marked as processed.");
        header('Location: ' . BASE_URL . '/admin/secretary/withdrawals.php?filter=processed');
        exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['pending', 'under_review', 'approved', 'declined', 'processed', 'all'];
if (!in_array($filter, $validFilters)) $filter = 'pending';

$where  = $filter === 'all' ? '' : 'WHERE w.status = ?';
$params = $filter === 'all' ? [] : [$filter];

$stmt = $pdo->prepare("SELECT w.*, m.name AS member_name, m.mno, m.bank_name, m.account_number,
    a.name AS reviewed_by_name, ap.name AS approved_by_name
    FROM savings_withdrawals w
    JOIN members m ON w.member_id = m.id
    LEFT JOIN admins a  ON w.reviewed_by = a.id
    LEFT JOIN admins ap ON w.approved_by = ap.id
    $where ORDER BY w.requested_at DESC");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filter Tabs -->
<div class="card mb-0">
    <div class="card-header p-2">
        <ul class="nav nav-pills flex-wrap">
            <?php foreach (['pending' => 'Pending', 'under_review' => 'Under Review', 'approved' => 'Approved', 'declined' => 'Declined', 'processed' => 'Processed', 'all' => 'All'] as $f => $lbl): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="card mt-0">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Withdrawal Requests</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th><th>Member</th><th>Bank Details</th><th>Type</th>
                    <th>Amount</th><th>Reason</th><th>Status</th><th>Requested</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($withdrawals)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No withdrawal requests found.</td></tr>
            <?php else: foreach ($withdrawals as $i => $w): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <strong><?= sanitize($w['member_name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($w['mno']) ?></small>
                </td>
                <td>
                    <small><?= sanitize($w['bank_name']) ?><br><?= sanitize($w['account_number']) ?></small>
                </td>
                <td><small><?= ucwords(str_replace('_', ' ', $w['withdrawal_type'])) ?></small></td>
                <td class="font-weight-bold"><?= formatCurrency($w['amount']) ?></td>
                <td><small><?= sanitize(substr($w['reason'] ?? '-', 0, 40)) ?></small></td>
                <td>
                    <?= statusBadge($w['status']) ?>
                    <?php if ($w['status'] === 'under_review' && $w['reviewed_by_name']): ?>
                    <br><small class="text-muted">by <?= sanitize($w['reviewed_by_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td><small><?= date('M d, Y', strtotime($w['requested_at'])) ?></small></td>
                <td>
                    <?php if (!empty($w['supporting_document'])): ?>
                    <a href="<?= BASE_URL ?>/uploads/documents/<?= urlencode($w['supporting_document']) ?>"
                       target="_blank" class="btn btn-xs btn-info mb-1" title="View attached document">
                        <i class="fas fa-file-alt"></i> Doc
                    </a><br>
                    <?php endif; ?>
                    <?php if ($w['status'] === 'pending'): ?>
                        <!-- Forward as applied -->
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="forward_withdrawal" value="1">
                            <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-success btn-confirm"
                                data-confirm="Forward <?= sanitize($w['member_name']) ?>'s withdrawal of <?= formatCurrency($w['amount']) ?> to Director as applied?">
                                <i class="fas fa-arrow-right"></i> Forward
                            </button>
                        </form>
                        <!-- Review & Forward -->
                        <button class="btn btn-xs btn-warning" data-toggle="modal" data-target="#reviewModal-<?= $w['id'] ?>">
                            <i class="fas fa-edit"></i> Review &amp; Forward
                        </button>
                    <?php elseif ($w['status'] === 'approved'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                            <button name="action" value="process" class="btn btn-xs btn-primary btn-confirm"
                                data-confirm="Mark withdrawal #<?= $w['id'] ?> as processed?">
                                <i class="fas fa-check-double"></i> Process
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ── Review & Forward modals (outside table) ────────────────────────────── -->
<?php foreach ($withdrawals as $w): if ($w['status'] !== 'pending') continue; ?>
<div class="modal fade" id="reviewModal-<?= $w['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="forward_withdrawal" value="1">
                <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Review &amp; Forward Withdrawal</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3">
                        <strong><?= sanitize($w['member_name']) ?></strong> &mdash;
                        <?= ucwords(str_replace('_', ' ', $w['withdrawal_type'])) ?><br>
                        Applied Amount: <strong><?= formatCurrency($w['amount']) ?></strong><br>
                        <?php if ($w['reason']): ?>
                        Reason: <em><?= sanitize($w['reason']) ?></em><br>
                        <?php endif; ?>
                        <?php if (!empty($w['supporting_document'])): ?>
                        <a href="<?= BASE_URL ?>/uploads/documents/<?= urlencode($w['supporting_document']) ?>"
                           target="_blank" class="btn btn-xs btn-info mt-1">
                            <i class="fas fa-file-alt mr-1"></i>View Supporting Document
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">
                            Adjusted Amount (&#8358;)
                            <small class="text-muted font-weight-normal ml-1">— leave blank to keep <?= formatCurrency($w['amount']) ?></small>
                        </label>
                        <input type="number" name="adjusted_amount" class="form-control" min="1" max="<?= $w['amount'] ?>" step="any"
                               placeholder="Leave blank to keep original amount">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">
                            Secretary Notes <small class="text-muted font-weight-normal">(optional)</small>
                        </label>
                        <textarea name="review_notes" class="form-control" rows="3"
                            placeholder="Observations or remarks for the Director..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-arrow-right mr-1"></i>Forward to Director
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
