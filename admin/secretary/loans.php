<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Loan Management';

// ── Forward loan to Director (with optional amount adjustment) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_loan'])) {
    $loanId         = (int)($_POST['loan_id'] ?? 0);
    $adjustedAmount = (float)($_POST['adjusted_amount'] ?? 0);
    $reviewNotes    = trim($_POST['review_notes'] ?? '');

    if ($loanId) {
        if ($adjustedAmount > 0) {
            $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW(), amount=? WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $adjustedAmount, $loanId]);
        } else {
            $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $loanId]);
        }

        $loanRow = $pdo->prepare("SELECT l.amount, l.loan_type, m.name AS mname, m.id AS member_id FROM loans l JOIN members m ON l.member_id=m.id WHERE l.id=?");
        $loanRow->execute([$loanId]);
        $lr = $loanRow->fetch();

        if ($lr) {
            $noteText = $reviewNotes ? " Secretary note: $reviewNotes" : '';
            $logDetail = "Loan #$loanId forwarded." . ($adjustedAmount > 0 ? " Amount adjusted to $adjustedAmount." : '') . $noteText;
            logAudit($pdo, $_SESSION['user_id'], 'admin', 'loan_forwarded', $logDetail);

            $directors = $pdo->query("SELECT * FROM admins WHERE role='director' AND is_active=1")->fetchAll();
            foreach ($directors as $dir) {
                addNotification($pdo, $dir['id'], 'admin', 'Loan Awaiting Approval',
                    $lr['mname'] . "'s " . loanTypeName($lr['loan_type']) . ' of ' . formatCurrency($lr['amount']) . ' is ready for your approval.' . ($adjustedAmount > 0 ? ' (Amount adjusted by Secretary)' : ''),
                    'warning', BASE_URL . '/admin/director/loans.php');
            }
        }

        flashMessage('success', "Loan #$loanId forwarded to Director." . ($adjustedAmount > 0 ? ' Amount adjusted to ' . formatCurrency($adjustedAmount) . '.' : ''));
        header('Location: ' . BASE_URL . '/admin/secretary/loans.php?filter=under_review');
        exit;
    }
}

// ── Record individual loan payment ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $amount = (float)($_POST['pay_amount'] ?? 0);
    $date   = $_POST['pay_date'] ?? date('Y-m-d');
    $method = $_POST['pay_method'] ?? 'payroll_deduction';

    if ($loanId && $amount > 0) {
        $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, recorded_by) VALUES (?,?,?,?,?)")
            ->execute([$loanId, $amount, $date, $method, $_SESSION['user_id']]);

        $totalAmount = (float)$pdo->prepare("SELECT amount FROM loans WHERE id=?")->execute([$loanId]) ? $pdo->query("SELECT amount FROM loans WHERE id=$loanId")->fetchColumn() : 0;
        $repaid = getLoanRepaidAmount($pdo, $loanId);

        if ($repaid >= $totalAmount && $totalAmount > 0) {
            $pdo->prepare("UPDATE loans SET status='completed' WHERE id=?")->execute([$loanId]);
        } elseif ($repaid > 0) {
            $pdo->prepare("UPDATE loans SET status='repaying' WHERE id=? AND status='disbursed'")->execute([$loanId]);
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'payment_recorded', "Loan #$loanId, Amount: $amount");
        flashMessage('success', "Payment of " . formatCurrency($amount) . " recorded for Loan #$loanId.");
        header('Location: ' . BASE_URL . '/admin/secretary/loans.php?filter=repaying');
        exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['pending','under_review','approved','disbursed','repaying','completed','all'];
if (!in_array($filter, $validFilters)) $filter = 'pending';

$where  = $filter === 'all' ? '' : "WHERE l.status = ?";
$params = $filter === 'all' ? [] : [$filter];

$stmt = $pdo->prepare("SELECT l.*, m.name AS member_name, m.mno, m.department, m.gsm,
    COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id), 0) AS repaid,
    ROUND(l.amount / l.duration_months, 2) AS monthly_instalment,
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
        <ul class="nav nav-pills flex-wrap">
            <?php foreach (['pending'=>'Pending','under_review'=>'Under Review','approved'=>'Approved','disbursed'=>'Disbursed','repaying'=>'Repaying','completed'=>'Completed','all'=>'All'] as $f => $l): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $l ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="card mt-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Type</th><th>Amount</th><th>Monthly</th><th>Repaid / Bal</th><th>Duration</th><th>Status</th><th>Applied</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($loans)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No loans found.</td></tr>
            <?php else: foreach ($loans as $i => $loan):
                $balance = $loan['amount'] - $loan['repaid'];
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <strong><?= sanitize($loan['member_name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($loan['mno']) ?></small>
                </td>
                <td><small><?= loanTypeName($loan['loan_type']) ?></small></td>
                <td class="font-weight-bold"><?= formatCurrency($loan['amount']) ?></td>
                <td><small class="text-info"><?= formatCurrency($loan['monthly_instalment']) ?>/m</small></td>
                <td>
                    <small class="text-success"><?= formatCurrency($loan['repaid']) ?></small><br>
                    <small class="text-danger">Bal: <?= formatCurrency(max(0, $balance)) ?></small>
                </td>
                <td><?= $loan['duration_months'] ?>m</td>
                <td><?= statusBadge($loan['status']) ?></td>
                <td><small><?= date('M d, Y', strtotime($loan['applied_at'])) ?></small></td>
                <td>
                    <?php if ($loan['status'] === 'pending'): ?>
                    <button class="btn btn-xs btn-info" data-toggle="modal" data-target="#forwardModal"
                        data-id="<?= $loan['id'] ?>"
                        data-name="<?= sanitize($loan['member_name']) ?>"
                        data-type="<?= sanitize(loanTypeName($loan['loan_type'])) ?>"
                        data-amount="<?= $loan['amount'] ?>"
                        data-months="<?= $loan['duration_months'] ?>">
                        <i class="fas fa-arrow-right"></i> Review
                    </button>
                    <?php elseif (in_array($loan['status'], ['disbursed','repaying'])): ?>
                    <button class="btn btn-xs btn-success" data-toggle="modal" data-target="#paymentModal"
                        data-id="<?= $loan['id'] ?>" data-balance="<?= $balance ?>">
                        <i class="fas fa-plus"></i> Payment
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

<!-- ── Forward / Review Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="forwardModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="loan_id" id="fwd_loan_id">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane mr-2"></i>Review &amp; Forward Loan</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3" id="fwd_loan_summary"></div>

                    <div class="form-group">
                        <label class="font-weight-bold">Applied Amount (&#8358;)</label>
                        <input type="number" id="fwd_original_amount" class="form-control bg-light" readonly>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">
                            Adjusted Amount (&#8358;)
                            <small class="text-muted font-weight-normal ml-1">— leave blank to keep original</small>
                        </label>
                        <input type="number" name="adjusted_amount" id="fwd_adjusted_amount"
                               class="form-control" min="0" step="100"
                               placeholder="Enter only if adjusting the applied amount">
                        <small class="text-warning" id="fwd_adjust_note" style="display:none">
                            <i class="fas fa-exclamation-triangle"></i> Amount will be changed from the applied value.
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Secretary Notes <small class="text-muted">(optional)</small></label>
                        <textarea name="review_notes" class="form-control" rows="2"
                            placeholder="Any notes for the Director (reason for adjustment, etc.)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="forward_loan" class="btn btn-info">
                        <i class="fas fa-arrow-right mr-1"></i>Forward to Director
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Payment Modal ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="loan_id" id="pay_loan_id">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave mr-2"></i>Record Loan Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Outstanding Balance: <strong class="text-danger" id="pay_balance_display"></strong></p>
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
                    <button type="submit" name="record_payment" class="btn btn-success">
                        <i class="fas fa-save mr-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$('#forwardModal').on('show.bs.modal', function(e) {
    var btn = $(e.relatedTarget);
    $('#fwd_loan_id').val(btn.data('id'));
    $('#fwd_original_amount').val(btn.data('amount'));
    $('#fwd_adjusted_amount').val('');
    $('#fwd_adjust_note').hide();
    $('#fwd_loan_summary').html(
        '<strong>' + btn.data('name') + '</strong> &mdash; ' + btn.data('type') +
        '<br>Applied: <strong>&#8358;' + parseFloat(btn.data('amount')).toLocaleString() +
        '</strong> &nbsp;|&nbsp; Duration: <strong>' + btn.data('months') + ' months</strong>'
    );
});
$('#fwd_adjusted_amount').on('input', function() {
    var orig = parseFloat($('#fwd_original_amount').val()) || 0;
    var adj  = parseFloat($(this).val()) || 0;
    $('#fwd_adjust_note').toggle(adj > 0 && adj !== orig);
});
$('#paymentModal').on('show.bs.modal', function(e) {
    var btn = $(e.relatedTarget);
    $('#pay_loan_id').val(btn.data('id'));
    var bal = parseFloat(btn.data('balance'));
    $('#pay_balance_display').text('₦' + bal.toLocaleString());
    $('#pay_amount').attr('max', bal).val('');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
