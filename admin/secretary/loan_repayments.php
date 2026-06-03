<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Loan Repayments';

// ── Individual repayment ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_single'])) {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $date   = $_POST['payment_date'] ?? date('Y-m-d');
    $method = $_POST['payment_method'] ?? 'payroll_deduction';
    $notes  = trim($_POST['notes'] ?? '');

    if ($loanId && $amount > 0) {
        $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, notes, recorded_by) VALUES (?,?,?,?,?,?)")
            ->execute([$loanId, $amount, $date, $method, $notes, $_SESSION['user_id']]);

        _updateLoanStatus($pdo, $loanId);
        logAudit($pdo, $_SESSION['user_id'], 'admin', 'loan_repayment_recorded', "Loan #$loanId, ₦$amount");
        flashMessage('success', formatCurrency($amount) . ' repayment recorded for Loan #' . $loanId . '.');
        header('Location: ' . BASE_URL . '/admin/secretary/loan_repayments.php');
        exit;
    }
    flashMessage('danger', 'Please select a loan and enter a valid amount.');
    header('Location: ' . BASE_URL . '/admin/secretary/loan_repayments.php');
    exit;
}

// ── Bulk CSV repayment ────────────────────────────────────────────────────────
$csvResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_repayment_csv'])) {
    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $handle  = fopen($_FILES['csv_file']['tmp_name'], 'r');
        fgetcsv($handle); // skip header

        $inserted = 0; $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) { $skipped++; continue; }

            $loanId  = (int)trim($row[0] ?? 0);
            $amount  = (float)trim($row[1] ?? 0);
            $date    = trim($row[2] ?? date('Y-m-d'));
            $method  = strtolower(trim($row[3] ?? 'payroll_deduction'));
            $notes   = trim($row[4] ?? '');

            if (!$loanId || $amount <= 0) {
                $csvResults[] = ['row' => "Loan #$loanId", 'status' => 'error', 'msg' => 'Invalid loan_id or amount'];
                $skipped++; continue;
            }

            // Verify loan exists
            $chk = $pdo->prepare("SELECT l.id, l.amount, m.name, m.mno FROM loans l JOIN members m ON l.member_id=m.id WHERE l.id=? AND l.status IN ('approved','disbursed','repaying')");
            $chk->execute([$loanId]);
            $loan = $chk->fetch();

            if (!$loan) {
                $csvResults[] = ['row' => "Loan #$loanId", 'status' => 'error', 'msg' => 'Loan not found or not active'];
                $skipped++; continue;
            }

            // Validate date
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d');
            }

            $validMethods = ['payroll_deduction','bank_transfer','cash'];
            if (!in_array($method, $validMethods)) $method = 'payroll_deduction';

            $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, notes, recorded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$loanId, $amount, $date, $method, $notes, $_SESSION['user_id']]);

            _updateLoanStatus($pdo, $loanId);
            $csvResults[] = ['row' => "Loan #$loanId", 'status' => 'success',
                'msg' => sanitize($loan['mno'] . ' – ' . $loan['name']) . ': ' . formatCurrency($amount) . ' recorded'];
            $inserted++;
        }
        fclose($handle);

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'bulk_repayment_imported', "Inserted: $inserted, Skipped: $skipped");
        flashMessage('success', "Bulk import complete. <strong>$inserted inserted</strong>, $skipped skipped.");
    } else {
        flashMessage('danger', 'Please select a CSV file.');
    }
}

// Helper: update loan status after payment
function _updateLoanStatus(PDO $pdo, int $loanId): void {
    $row = $pdo->prepare("SELECT amount FROM loans WHERE id=?");
    $row->execute([$loanId]);
    $total = (float)$row->fetchColumn();
    $repaid = getLoanRepaidAmount($pdo, $loanId);
    if ($repaid >= $total && $total > 0) {
        $pdo->prepare("UPDATE loans SET status='completed' WHERE id=?")->execute([$loanId]);
    } elseif ($repaid > 0) {
        $pdo->prepare("UPDATE loans SET status='repaying' WHERE id=? AND status IN ('approved','disbursed')")->execute([$loanId]);
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
// Active loans for individual entry dropdown
$activeLoans = $pdo->query("SELECT l.id, l.loan_type, l.amount, l.duration_months,
    COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id=l.id),0) AS repaid,
    m.name AS member_name, m.mno
    FROM loans l JOIN members m ON l.member_id=m.id
    WHERE l.status IN ('approved','disbursed','repaying')
    ORDER BY m.name, l.id")->fetchAll();

// Recent repayments
$recentPayments = $pdo->query("SELECT lp.*, m.name AS member_name, m.mno, a.name AS recorded_by_name,
    l.loan_type, l.amount AS loan_amount
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    JOIN members m ON l.member_id = m.id
    LEFT JOIN admins a ON lp.recorded_by = a.id
    ORDER BY lp.created_at DESC LIMIT 60")->fetchAll();

$totalRepaid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-single"><i class="fas fa-user mr-1"></i>Single Entry</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-bulk"><i class="fas fa-file-csv mr-1"></i>Bulk CSV</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-history"><i class="fas fa-history mr-1"></i>History</a></li>
</ul>

<div class="tab-content">

<!-- ══ TAB: SINGLE ENTRY ════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tab-single">
<div class="row justify-content-center">
<div class="col-md-6">
    <div class="card card-success">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-money-bill-wave mr-2"></i>Record Individual Repayment</h3></div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label class="font-weight-bold">Select Loan <span class="text-danger">*</span></label>
                    <select name="loan_id" id="loanSelect" class="form-control" required>
                        <option value="">-- Select Member / Loan --</option>
                        <?php foreach ($activeLoans as $l):
                            $bal = $l['amount'] - $l['repaid'];
                        ?>
                        <option value="<?= $l['id'] ?>"
                            data-balance="<?= $bal ?>"
                            data-monthly="<?= round($l['amount'] / $l['duration_months'], 2) ?>">
                            <?= sanitize($l['mno']) ?> — <?= sanitize($l['member_name']) ?>
                            | <?= loanTypeName($l['loan_type']) ?>
                            | Bal: <?= formatCurrency($bal) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="loanInfo" class="alert alert-info small mb-3" style="display:none">
                    Outstanding balance: <strong id="loanBalance"></strong> &nbsp;|&nbsp;
                    Suggested monthly: <strong id="loanMonthly"></strong>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Amount (&#8358;) <span class="text-danger">*</span></label>
                    <input type="number" name="amount" id="repayAmount" class="form-control" min="1" step="100" required>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="payroll_deduction">Payroll Deduction</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Notes <small class="text-muted">(optional)</small></label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g. June salary deduction">
                </div>
                <button type="submit" name="record_single" class="btn btn-success btn-block">
                    <i class="fas fa-save mr-2"></i>Record Repayment
                </button>
            </form>
        </div>
    </div>
</div>
</div>
</div><!-- end tab-single -->

<!-- ══ TAB: BULK CSV ════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-bulk">
<div class="row">
    <div class="col-md-5">
        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-upload mr-2"></i>Bulk Repayment CSV Upload</h3></div>
            <div class="card-body">
                <p class="text-muted small">Upload repayments for many loans at once from a payroll deduction schedule.</p>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <div class="custom-file">
                            <input type="file" name="csv_file" class="custom-file-input" accept=".csv" required id="repCsvFile">
                            <label class="custom-file-label" for="repCsvFile">Choose CSV file...</label>
                        </div>
                    </div>
                    <button type="submit" name="bulk_repayment_csv" class="btn btn-primary btn-block mt-2">
                        <i class="fas fa-cloud-upload-alt mr-2"></i>Upload &amp; Process
                    </button>
                </form>

                <hr>
                <h6 class="font-weight-bold">Required CSV Format</h6>
                <div class="table-responsive">
                <table class="table table-sm table-bordered small mb-2">
                    <thead class="thead-light"><tr><th>loan_id</th><th>amount</th><th>payment_date</th><th>payment_method</th><th>notes</th></tr></thead>
                    <tbody>
                        <tr><td>5</td><td>15000</td><td>2026-06-30</td><td>payroll_deduction</td><td>June</td></tr>
                        <tr><td>8</td><td>20000</td><td>2026-06-30</td><td>payroll_deduction</td><td></td></tr>
                    </tbody>
                </table>
                </div>
                <ul class="small text-muted pl-3 mb-2">
                    <li>First row must be a header row (skipped)</li>
                    <li><strong>loan_id</strong>: the numeric ID from the loans table</li>
                    <li><strong>payment_method</strong>: payroll_deduction, bank_transfer, or cash</li>
                    <li><strong>payment_date</strong> format: YYYY-MM-DD</li>
                    <li>notes column is optional</li>
                </ul>

                <a href="data:text/csv;charset=utf-8,loan_id%2Camount%2Cpayment_date%2Cpayment_method%2Cnotes%0A5%2C15000%2C<?= date('Y-m-d') ?>%2Cpayroll_deduction%2CJune%20salary%0A"
                   download="repayment_template.csv" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-download mr-1"></i>Download Template
                </a>

                <!-- Loan ID reference table -->
                <hr>
                <h6 class="font-weight-bold small">Active Loans Reference</h6>
                <div class="table-responsive" style="max-height:250px;overflow-y:auto">
                <table class="table table-sm table-bordered small mb-0">
                    <thead class="thead-light"><tr><th>Loan ID</th><th>Member</th><th>Type</th><th>Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($activeLoans as $l): ?>
                    <tr>
                        <td><strong><?= $l['id'] ?></strong></td>
                        <td><?= sanitize($l['mno']) ?><br><?= sanitize($l['member_name']) ?></td>
                        <td><small><?= loanTypeName($l['loan_type']) ?></small></td>
                        <td><?= formatCurrency($l['amount'] - $l['repaid']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <?php if (!empty($csvResults)): ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Import Results</h3></div>
            <div class="card-body p-0" style="max-height:500px;overflow-y:auto">
                <table class="table table-sm mb-0">
                    <thead class="thead-light"><tr><th>Loan</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($csvResults as $r): ?>
                    <tr class="<?= $r['status'] === 'success' ? 'table-success' : 'table-danger' ?>">
                        <td><strong><?= sanitize($r['row']) ?></strong></td>
                        <td>
                            <i class="fas fa-<?= $r['status'] === 'success' ? 'check-circle text-success' : 'times-circle text-danger' ?> mr-1"></i>
                            <?= $r['msg'] ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="fas fa-file-csv fa-3x mb-3"></i><br>Upload a CSV file to see import results here.
        </div></div>
        <?php endif; ?>
    </div>
</div>
</div><!-- end tab-bulk -->

<!-- ══ TAB: HISTORY ═════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-history">
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Loan Repayments (last 60)</h3>
        <span class="badge badge-success">Total Repaid: <?= formatCurrency($totalRepaid) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Loan #</th><th>Member</th><th>Type</th><th>Amount</th><th>Date</th><th>Method</th><th>Notes</th><th>Recorded By</th></tr>
            </thead>
            <tbody>
            <?php if (empty($recentPayments)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No repayments recorded yet.</td></tr>
            <?php else: foreach ($recentPayments as $i => $p): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong>#<?= $p['loan_id'] ?></strong></td>
                <td><?= sanitize($p['member_name']) ?><br><small class="text-muted"><?= sanitize($p['mno']) ?></small></td>
                <td><small><?= loanTypeName($p['loan_type']) ?></small></td>
                <td class="text-success font-weight-bold"><?= formatCurrency($p['amount']) ?></td>
                <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                <td><span class="badge badge-secondary"><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></span></td>
                <td><small><?= sanitize($p['notes'] ?? '—') ?></small></td>
                <td><small><?= sanitize($p['recorded_by_name'] ?? 'System') ?></small></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</div><!-- end tab-history -->

</div><!-- end tab-content -->

<script>
$('#loanSelect').on('change', function() {
    var opt = $(this).find(':selected');
    var bal = parseFloat(opt.data('balance')) || 0;
    var mon = parseFloat(opt.data('monthly')) || 0;
    if (bal > 0) {
        $('#loanBalance').text('₦' + bal.toLocaleString());
        $('#loanMonthly').text('₦' + mon.toLocaleString());
        $('#loanInfo').show();
        $('#repayAmount').val(mon).attr('max', bal);
    } else {
        $('#loanInfo').hide();
        $('#repayAmount').val('').removeAttr('max');
    }
});
$('.custom-file-input').on('change', function() {
    var n = $(this).val().split('\\').pop();
    $(this).siblings('.custom-file-label').text(n || 'Choose CSV file...');
});
<?php if (!empty($csvResults)): ?>
$('[href="#tab-bulk"]').tab('show');
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
