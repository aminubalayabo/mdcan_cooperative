<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Loan Management';

// Secretary reviews loan → sends to director
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_loan'])) {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($loanId && $action === 'forward') {
        $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
            ->execute([$_SESSION['user_id'], $loanId]);

        // Notify director
        $dir = $pdo->query("SELECT id FROM admins WHERE role='director' LIMIT 1")->fetch();
        $loan = $pdo->prepare("SELECT l.*, m.name FROM loans l JOIN members m ON l.member_id=m.id WHERE l.id=?")->execute([$loanId]) ? null : null;
        $loanRow = $pdo->prepare("SELECT l.amount, l.loan_type, m.name AS mname FROM loans l JOIN members m ON l.member_id=m.id WHERE l.id=?");
        $loanRow->execute([$loanId]);
        $lr = $loanRow->fetch();

        if ($dir && $lr) {
            addNotification($pdo, $dir['id'], 'admin', 'Loan Awaiting Approval',
                $lr['mname'] . '\'s ' . loanTypeName($lr['loan_type']) . ' of ' . formatCurrency($lr['amount']) . ' is ready for your approval.',
                'warning', BASE_URL . '/admin/director/loans.php');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'loan_forwarded', "Loan ID: $loanId forwarded to director");
        flashMessage('success', "Loan #$loanId forwarded to Director for approval.");
        header('Location: ' . BASE_URL . '/admin/secretary/loans.php');
        exit;
    }

    if ($loanId && $action === 'record_payment') {
        $amount = (float)($_POST['pay_amount'] ?? 0);
        $date   = $_POST['pay_date'] ?? date('Y-m-d');
        $method = $_POST['pay_method'] ?? 'payroll_deduction';

        if ($amount > 0) {
            $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, recorded_by) VALUES (?,?,?,?,?)")
                ->execute([$loanId, $amount, $date, $method, $_SESSION['user_id']]);

            // Check if fully paid
            $loanData = $pdo->prepare("SELECT amount FROM loans WHERE id=?");
            $loanData->execute([$loanId]);
            $totalAmount = (float)$loanData->fetchColumn();
            $repaid = getLoanRepaidAmount($pdo, $loanId);

            if ($repaid >= $totalAmount) {
                $pdo->prepare("UPDATE loans SET status='completed' WHERE id=?")->execute([$loanId]);
            } elseif ($repaid > 0) {
                $pdo->prepare("UPDATE loans SET status='repaying' WHERE id=? AND status='disbursed'")->execute([$loanId]);
            }

            logAudit($pdo, $_SESSION['user_id'], 'admin', 'payment_recorded', "Loan ID: $loanId, Amount: $amount");
            flashMessage('success', "Payment of " . formatCurrency($amount) . " recorded.");
            header('Location: ' . BASE_URL . '/admin/secretary/loans.php');
            exit;
        }
    }
}

$filter = $_GET['filter'] ?? 'pending';
$where  = $filter === 'all' ? '' : "WHERE l.status = ?";
$params = $filter === 'all' ? [] : [$filter];

$stmt = $pdo->prepare("SELECT l.*, m.name AS member_name, m.mno, m.department,
    (SELECT COALESCE(SUM(amount),0) FROM loan_payments lp WHERE lp.loan_id = l.id) AS repaid,
    a.name AS reviewed_by_name
    FROM loans l
    JOIN members m ON l.member_id = m.id
    LEFT JOIN admins a ON l.reviewed_by = a.id
    $where ORDER BY l.applied_at DESC");
$stmt->execute($params);
$loans = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filter Tabs -->
<div class="card mb-0">
    <div class="card-header p-2">
        <ul class="nav nav-pills">
            <?php foreach (['pending'=>'Pending','under_review'=>'Under Review','approved'=>'Approved','disbursed'=>'Disbursed','repaying'=>'Repaying','completed'=>'Completed','all'=>'All'] as $f => $l): ?>
            <li class="nav-item"><a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $l ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="card mt-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Type</th><th>Amount</th><th>Repaid</th><th>Duration</th><th>Status</th><th>Applied</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($loans)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No loans found.</td></tr>
            <?php else: foreach ($loans as $i => $loan):
                $balance = $loan['amount'] - $loan['repaid'];
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($loan['member_name']) ?></strong><br><small><?= sanitize($loan['mno']) ?></small></td>
                <td><small><?= loanTypeName($loan['loan_type']) ?></small></td>
                <td><?= formatCurrency($loan['amount']) ?></td>
                <td>
                    <small class="text-success"><?= formatCurrency($loan['repaid']) ?></small><br>
                    <small class="text-danger">Bal: <?= formatCurrency(max(0, $balance)) ?></small>
                </td>
                <td><?= $loan['duration_months'] ?>m</td>
                <td><?= statusBadge($loan['status']) ?></td>
                <td><small><?= date('M d, Y', strtotime($loan['applied_at'])) ?></small></td>
                <td>
                    <?php if ($loan['status'] === 'pending'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                        <button type="submit" name="review_loan" value="1" class="btn btn-xs btn-info"
                            onclick="document.querySelector('input[name=action]').value='forward'"
                            title="Forward to Director">
                            <i class="fas fa-arrow-right"></i> Forward
                        </button>
                        <input type="hidden" name="action" value="forward">
                    </form>
                    <?php elseif (in_array($loan['status'], ['disbursed','repaying'])): ?>
                    <button class="btn btn-xs btn-success" data-toggle="modal" data-target="#paymentModal"
                        data-id="<?= $loan['id'] ?>" data-balance="<?= $balance ?>">
                        <i class="fas fa-plus"></i> Payment
                    </button>
                    <?php else: echo '<span class="text-muted">-</span>'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="review_loan" value="1">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="loan_id" id="pay_loan_id">
                <div class="modal-header"><h5 class="modal-title">Record Loan Payment</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
                <div class="modal-body">
                    <p>Balance: <strong id="pay_balance_display"></strong></p>
                    <div class="form-group">
                        <label>Amount (&#8358;)</label>
                        <input type="number" name="pay_amount" id="pay_amount" class="form-control" min="1" step="100" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="pay_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Method</label>
                        <select name="pay_method" class="form-control">
                            <option value="payroll_deduction">Payroll Deduction</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i>Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$('#paymentModal').on('show.bs.modal', function(e) {
    var btn = $(e.relatedTarget);
    $('#pay_loan_id').val(btn.data('id'));
    var bal = parseFloat(btn.data('balance'));
    $('#pay_balance_display').text('₦' + bal.toLocaleString());
    $('#pay_amount').attr('max', bal);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
