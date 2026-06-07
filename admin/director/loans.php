<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Loan Approvals';

// ── Approve / Decline ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes  = trim($_POST['notes'] ?? '');

    if ($loanId && in_array($action, ['approve','decline'], true)) {
        $status = $action === 'approve' ? 'approved' : 'declined';
        $pdo->prepare("UPDATE loans SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='under_review'")
            ->execute([$status, $_SESSION['user_id'], $loanId]);

        $loanStmt = $pdo->prepare("SELECT member_id, loan_type, amount FROM loans WHERE id=?");
        $loanStmt->execute([$loanId]);
        $loanData = $loanStmt->fetch();

        if ($loanData) {
            $msg = $action === 'approve'
                ? 'Your ' . loanTypeName($loanData['loan_type']) . ' of ' . formatCurrency($loanData['amount']) . ' has been APPROVED.'
                : 'Your ' . loanTypeName($loanData['loan_type']) . ' of ' . formatCurrency($loanData['amount']) . ' was DECLINED. ' . $notes;
            addNotification($pdo, $loanData['member_id'], 'member',
                'Loan ' . ucfirst($action) . 'd', $msg,
                $action === 'approve' ? 'success' : 'danger');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', "loan_$action", "Loan ID: $loanId. Notes: $notes");
        flashMessage('success', "Loan #$loanId has been {$action}d.");
        header('Location: ' . BASE_URL . '/admin/director/loans.php?filter=under_review');
        exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'under_review';
$validFilters = ['all','under_review','pending','approved','declined','disbursed','repaying','completed'];
if (!in_array($filter, $validFilters)) $filter = 'under_review';

$where  = $filter === 'all' ? '' : "WHERE l.status = ?";
$params = $filter === 'all' ? [] : [$filter];

$stmt = $pdo->prepare("SELECT l.*, m.name AS member_name, m.mno, m.department, m.gsm,
    a1.name AS reviewed_by_name, a2.name AS approved_by_name,
    (SELECT COUNT(*) FROM loan_guarantors lg WHERE lg.loan_id = l.id) AS guarantor_count,
    (SELECT COUNT(*) FROM loan_guarantors lg WHERE lg.loan_id = l.id AND lg.consent_status = 'accepted') AS guarantors_accepted
    FROM loans l
    JOIN members m ON l.member_id = m.id
    LEFT JOIN admins a1 ON l.reviewed_by = a1.id
    LEFT JOIN admins a2 ON l.approved_by = a2.id
    $where
    ORDER BY l.applied_at DESC");
$stmt->execute($params);
$loans = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filter Tabs -->
<div class="card">
    <div class="card-header p-2">
        <ul class="nav nav-pills flex-wrap">
            <?php foreach (['under_review'=>'Awaiting Approval','pending'=>'Pending Review','approved'=>'Approved','declined'=>'Declined','all'=>'All Loans'] as $f => $lbl): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th><th>Member</th><th>Loan Type</th><th>Amount</th><th>Monthly</th>
                    <th>Duration</th><th>Reviewed By</th><th>Guarantor</th><th>Status</th><th>Applied</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($loans)): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No loans found.</td></tr>
            <?php else: foreach ($loans as $i => $loan): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <strong><?= sanitize($loan['member_name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($loan['mno']) ?></small>
                </td>
                <td><?= loanTypeName($loan['loan_type']) ?></td>
                <td class="font-weight-bold"><?= formatCurrency($loan['amount']) ?></td>
                <td><small class="text-info"><?= formatCurrency(round($loan['amount'] / $loan['duration_months'], 2)) ?>/m</small></td>
                <td><?= $loan['duration_months'] ?>m</td>
                <td><small><?= sanitize($loan['reviewed_by_name'] ?? '—') ?></small></td>
                <td>
                    <?php if ($loan['requires_guarantor']): ?>
                    <span class="badge badge-<?= $loan['guarantors_accepted'] > 0 ? 'success' : 'warning' ?>">
                        <?= $loan['guarantors_accepted'] ?>/<?= $loan['guarantor_count'] ?> consented
                    </span>
                    <?php else: ?>
                    <span class="badge badge-secondary">N/A</span>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($loan['status']) ?></td>
                <td><?= date('M d, Y', strtotime($loan['applied_at'])) ?></td>
                <td>
                <?php if ($loan['status'] === 'under_review'): ?>
                    <!-- Approve: inline form, no modal needed -->
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-xs btn-success btn-confirm"
                            data-confirm="Approve <?= sanitize($loan['member_name']) ?>'s loan of <?= formatCurrency($loan['amount']) ?>?">
                            <i class="fas fa-check mr-1"></i>Approve
                        </button>
                    </form>
                    <!-- Decline: per-loan modal for decline reason -->
                    <button class="btn btn-xs btn-danger" data-toggle="modal" data-target="#declineModal-<?= $loan['id'] ?>">
                        <i class="fas fa-times mr-1"></i>Decline
                    </button>
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

<!-- ── Per-loan Decline Modals (outside table for valid HTML) ─────────────── -->
<?php foreach ($loans as $loan): ?>
<?php if ($loan['status'] === 'under_review'): ?>
<div class="modal fade" id="declineModal-<?= $loan['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <input type="hidden" name="action" value="decline">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i>Decline Loan Application</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>You are declining the loan application by <strong><?= sanitize($loan['member_name']) ?></strong>
                    for <strong><?= formatCurrency($loan['amount']) ?></strong> over <?= $loan['duration_months'] ?> months.</p>
                    <p class="text-muted small">The applicant will be notified of the decision.</p>
                    <div class="form-group">
                        <label>Reason for Decline <small class="text-muted">(optional but recommended)</small></label>
                        <textarea name="notes" class="form-control" rows="3"
                            placeholder="Provide a reason so the member understands the decision..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times mr-1"></i>Decline Loan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
