<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Loan Approvals';

// Handle approve/decline
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes  = trim($_POST['notes'] ?? '');

    if ($loanId && in_array($action, ['approve','decline'], true)) {
        $status = $action === 'approve' ? 'approved' : 'declined';
        $stmt = $pdo->prepare("UPDATE loans SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='under_review'");
        $stmt->execute([$status, $_SESSION['user_id'], $loanId]);

        $loan = $pdo->prepare("SELECT member_id, loan_type, amount FROM loans WHERE id=?");
        $loan->execute([$loanId]);
        $loanData = $loan->fetch();

        if ($loanData) {
            $msg = $action === 'approve'
                ? 'Your ' . loanTypeName($loanData['loan_type']) . ' of ' . formatCurrency($loanData['amount']) . ' has been APPROVED.'
                : 'Your ' . loanTypeName($loanData['loan_type']) . ' of ' . formatCurrency($loanData['amount']) . ' was DECLINED. ' . $notes;
            addNotification($pdo, $loanData['member_id'], 'member', 'Loan ' . ucfirst($action) . 'd', $msg,
                $action === 'approve' ? 'success' : 'danger');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', "loan_$action", "Loan ID: $loanId. Notes: $notes");
        flashMessage('success', "Loan #$loanId has been {$action}d.");
        header('Location: ' . BASE_URL . '/admin/director/loans.php');
        exit;
    }
}

$filter = $_GET['filter'] ?? 'under_review';
$validFilters = ['all','under_review','pending','approved','declined','disbursed','repaying','completed'];
if (!in_array($filter, $validFilters)) $filter = 'under_review';

$where = $filter === 'all' ? '' : "WHERE l.status = ?";
$params = $filter === 'all' ? [] : [$filter];

$sql = "SELECT l.*, m.name AS member_name, m.mno, m.department, m.gsm,
        a1.name AS reviewed_by_name, a2.name AS approved_by_name,
        (SELECT COUNT(*) FROM loan_guarantors lg WHERE lg.loan_id = l.id) AS guarantor_count,
        (SELECT COUNT(*) FROM loan_guarantors lg WHERE lg.loan_id = l.id AND lg.consent_status = 'accepted') AS guarantors_accepted
    FROM loans l
    JOIN members m ON l.member_id = m.id
    LEFT JOIN admins a1 ON l.reviewed_by = a1.id
    LEFT JOIN admins a2 ON l.approved_by = a2.id
    $where
    ORDER BY l.applied_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filter Tabs -->
<div class="card">
    <div class="card-header p-2">
        <ul class="nav nav-pills">
            <?php $filters = ['under_review'=>'Awaiting Approval','pending'=>'Pending Review','approved'=>'Approved','declined'=>'Declined','all'=>'All Loans']; ?>
            <?php foreach ($filters as $f => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $label ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Loan Type</th><th>Amount</th><th>Duration</th><th>Guarantor</th><th>Status</th><th>Applied</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($loans)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No loans found.</td></tr>
            <?php else: foreach ($loans as $i => $loan): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <strong><?= sanitize($loan['member_name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($loan['mno']) ?></small>
                </td>
                <td><?= loanTypeName($loan['loan_type']) ?></td>
                <td class="font-weight-bold"><?= formatCurrency($loan['amount']) ?></td>
                <td><?= $loan['duration_months'] ?>m</td>
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
                    <button class="btn btn-xs btn-success" data-toggle="modal" data-target="#actionModal"
                        data-id="<?= $loan['id'] ?>" data-action="approve" data-name="<?= sanitize($loan['member_name']) ?>" data-amount="<?= $loan['amount'] ?>">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-xs btn-danger" data-toggle="modal" data-target="#actionModal"
                        data-id="<?= $loan['id'] ?>" data-action="decline" data-name="<?= sanitize($loan['member_name']) ?>" data-amount="<?= $loan['amount'] ?>">
                        <i class="fas fa-times"></i>
                    </button>
                    <?php else: ?>
                    <span class="text-muted small">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Action Modal -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Loan Action</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="loan_id" id="modal_loan_id">
                    <input type="hidden" name="action" id="modal_action">
                    <p id="modal_message"></p>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Reason or notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="modal_btn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$('#actionModal').on('show.bs.modal', function(e) {
    var btn = $(e.relatedTarget);
    var id = btn.data('id'), action = btn.data('action');
    var name = btn.data('name'), amount = btn.data('amount');
    $('#modal_loan_id').val(id);
    $('#modal_action').val(action);
    $('#modalTitle').text(action === 'approve' ? 'Approve Loan' : 'Decline Loan');
    $('#modal_message').html('Are you sure you want to <strong>' + action + '</strong> the loan application by <strong>' + name + '</strong> for <strong>&#8358;' + parseFloat(amount).toLocaleString() + '</strong>?');
    $('#modal_btn').removeClass('btn-success btn-danger').addClass(action === 'approve' ? 'btn-success' : 'btn-danger').text(action === 'approve' ? 'Approve' : 'Decline');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
