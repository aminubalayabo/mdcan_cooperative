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
    } else {
        flashMessage('danger', 'Please select a loan and enter a valid amount.');
    }
    header('Location: ' . BASE_URL . '/admin/secretary/loan_repayments.php');
    exit;
}

// ── Bulk CSV repayment (MNO-based) ────────────────────────────────────────────
$csvResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_repayment_csv'])) {
    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $handle  = fopen($_FILES['csv_file']['tmp_name'], 'r');
        fgetcsv($handle); // skip header row

        $inserted = 0; $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) { $skipped++; continue; }

            $mno    = strtoupper(trim($row[0] ?? ''));
            $amount = (float)trim($row[1] ?? 0);
            $date   = trim($row[2] ?? date('Y-m-d'));
            $method = strtolower(trim($row[3] ?? 'payroll_deduction'));
            $notes  = trim($row[4] ?? '');

            if (!$mno || $amount <= 0) {
                $csvResults[] = ['row' => $mno ?: '(empty)', 'status' => 'error', 'msg' => 'Missing MNO or invalid amount'];
                $skipped++; continue;
            }

            // Lookup member by MNO
            $ms = $pdo->prepare("SELECT id, name FROM members WHERE mno = ? AND status = 'active'");
            $ms->execute([$mno]);
            $member = $ms->fetch();

            if (!$member) {
                $csvResults[] = ['row' => $mno, 'status' => 'error', 'msg' => 'MNO not found or member inactive'];
                $skipped++; continue;
            }

            // Get oldest active loan for this member (applied_at ASC)
            $lq = $pdo->prepare("SELECT id, amount, duration_months FROM loans
                WHERE member_id = ? AND status IN ('approved','disbursed','repaying')
                ORDER BY applied_at ASC LIMIT 1");
            $lq->execute([$member['id']]);
            $loan = $lq->fetch();

            if (!$loan) {
                $csvResults[] = ['row' => $mno, 'status' => 'error', 'msg' => sanitize($member['name']) . ' — no active loan found'];
                $skipped++; continue;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
            $validMethods = ['payroll_deduction','bank_transfer','cash'];
            if (!in_array($method, $validMethods)) $method = 'payroll_deduction';

            $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, notes, recorded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$loan['id'], $amount, $date, $method, $notes, $_SESSION['user_id']]);

            _updateLoanStatus($pdo, $loan['id']);
            $csvResults[] = ['row' => $mno, 'status' => 'success',
                'msg' => sanitize($member['name']) . ' — Loan #' . $loan['id'] . ': ' . formatCurrency($amount) . ' recorded'];
            $inserted++;
        }
        fclose($handle);

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'bulk_repayment_imported', "Inserted: $inserted, Skipped: $skipped");
        flashMessage('success', "Bulk import complete. <strong>$inserted inserted</strong>, $skipped skipped.");
    } else {
        flashMessage('danger', 'Please select a CSV file.');
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────
function _updateLoanStatus(PDO $pdo, int $loanId): void {
    $row = $pdo->prepare("SELECT amount FROM loans WHERE id=?");
    $row->execute([$loanId]);
    $total  = (float)$row->fetchColumn();
    $repaid = getLoanRepaidAmount($pdo, $loanId);
    if ($total > 0 && $repaid >= $total) {
        $pdo->prepare("UPDATE loans SET status='completed' WHERE id=?")->execute([$loanId]);
    } elseif ($repaid > 0) {
        $pdo->prepare("UPDATE loans SET status='repaying' WHERE id=? AND status IN ('approved','disbursed')")->execute([$loanId]);
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
// Active members who have at least one active loan
$activeMembers = $pdo->query("SELECT DISTINCT m.id, m.name, m.mno
    FROM members m JOIN loans l ON l.member_id = m.id
    WHERE m.status = 'active' AND l.status IN ('approved','disbursed','repaying')
    ORDER BY m.name")->fetchAll();

// All active loans with member info (for the JS lookup and reference table)
$activeLoans = $pdo->query("SELECT l.id, l.loan_type, l.amount, l.duration_months,
    l.member_id,
    COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id=l.id),0) AS repaid,
    m.name AS member_name, m.mno
    FROM loans l JOIN members m ON l.member_id=m.id
    WHERE l.status IN ('approved','disbursed','repaying')
    ORDER BY m.name, l.applied_at ASC")->fetchAll();

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
                <!-- Step 1: select member by MNO -->
                <div class="form-group">
                    <label class="font-weight-bold">Member (MNO / Name) <span class="text-danger">*</span></label>
                    <select id="memberSelect" class="form-control" required>
                        <option value="">-- Select Member --</option>
                        <?php foreach ($activeMembers as $m): ?>
                        <option value="<?= $m['id'] ?>">
                            <?= sanitize($m['mno']) ?> — <?= sanitize($m['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Step 2: loan auto-populated from member selection -->
                <div class="form-group" id="loanGroup" style="display:none">
                    <label class="font-weight-bold">Active Loan <span class="text-danger">*</span></label>
                    <select name="loan_id" id="loanSelect" class="form-control" required>
                        <option value="">-- Select Loan --</option>
                    </select>
                </div>

                <div id="loanInfo" class="alert alert-info small mb-3" style="display:none">
                    Outstanding balance: <strong id="loanBalance"></strong>
                    &nbsp;|&nbsp; Suggested monthly: <strong id="loanMonthly"></strong>
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
            <div class="card-header"><h3 class="card-title"><i class="fas fa-upload mr-2"></i>Bulk Repayment CSV</h3></div>
            <div class="card-body">
                <p class="text-muted small">Upload repayments using Member Numbers (MNO). Payment is applied to the member's oldest active loan.</p>
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
                    <thead class="thead-light">
                        <tr><th>mno</th><th>amount</th><th>payment_date</th><th>payment_method</th><th>notes</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>MNO-0001</td><td>15000</td><td>2026-06-30</td><td>payroll_deduction</td><td>June</td></tr>
                        <tr><td>MNO-0002</td><td>20000</td><td>2026-06-30</td><td>payroll_deduction</td><td></td></tr>
                    </tbody>
                </table>
                </div>
                <ul class="small text-muted pl-3 mb-2">
                    <li>First row = header (skipped automatically)</li>
                    <li><strong>mno</strong>: Member Number e.g. MNO-0001</li>
                    <li>Payment applied to member's <em>oldest active loan</em> if multiple exist</li>
                    <li><strong>payment_method</strong>: payroll_deduction, bank_transfer, or cash</li>
                    <li><strong>payment_date</strong>: YYYY-MM-DD</li>
                    <li>notes column is optional</li>
                </ul>

                <!-- Download template -->
                <a href="data:text/csv;charset=utf-8,mno%2Camount%2Cpayment_date%2Cpayment_method%2Cnotes%0AMNO-0001%2C15000%2C<?= date('Y-m-d') ?>%2Cpayroll_deduction%2CJune%20salary%0A"
                   download="repayment_template.csv" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-download mr-1"></i>Download Template
                </a>

                <!-- MNO Reference -->
                <hr>
                <h6 class="font-weight-bold small">Active Loans Reference</h6>
                <div class="table-responsive" style="max-height:250px;overflow-y:auto">
                <table class="table table-sm table-bordered small mb-0">
                    <thead class="thead-light"><tr><th>MNO</th><th>Name</th><th>Loan #</th><th>Type</th><th>Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($activeLoans as $l): ?>
                    <tr>
                        <td><strong><?= sanitize($l['mno']) ?></strong></td>
                        <td><?= sanitize($l['member_name']) ?></td>
                        <td>#<?= $l['id'] ?></td>
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
                    <thead class="thead-light"><tr><th>MNO</th><th>Result</th></tr></thead>
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
                <tr><th>#</th><th>MNO</th><th>Member</th><th>Loan #</th><th>Type</th><th>Amount</th><th>Date</th><th>Method</th><th>Notes</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php if (empty($recentPayments)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No repayments recorded yet.</td></tr>
            <?php else: foreach ($recentPayments as $i => $p): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($p['mno']) ?></strong></td>
                <td><?= sanitize($p['member_name']) ?></td>
                <td>#<?= $p['loan_id'] ?></td>
                <td><small><?= loanTypeName($p['loan_type']) ?></small></td>
                <td class="text-success font-weight-bold"><?= formatCurrency($p['amount']) ?></td>
                <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                <td><small><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></small></td>
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
// Build loan lookup keyed by member_id
var loansByMember = {};
<?php foreach ($activeLoans as $l):
    $bal = $l['amount'] - $l['repaid'];
    $monthly = round($l['amount'] / $l['duration_months'], 2);
?>
if (!loansByMember[<?= $l['member_id'] ?>]) loansByMember[<?= $l['member_id'] ?>] = [];
loansByMember[<?= $l['member_id'] ?>].push({
    id:      <?= $l['id'] ?>,
    type:    "<?= addslashes(loanTypeName($l['loan_type'])) ?>",
    balance: <?= $bal ?>,
    monthly: <?= $monthly ?>
});
<?php endforeach; ?>

$('#memberSelect').on('change', function() {
    var mid   = $(this).val();
    var loans = loansByMember[mid] || [];
    var $sel  = $('#loanSelect');

    $sel.empty().append('<option value="">-- Select Loan --</option>');
    loans.forEach(function(l) {
        $sel.append(
            '<option value="' + l.id + '" data-balance="' + l.balance + '" data-monthly="' + l.monthly + '">' +
            'Loan #' + l.id + ' — ' + l.type + ' | Bal: ₦' + l.balance.toLocaleString() +
            '</option>'
        );
    });

    if (loans.length === 1) {
        $sel.val(loans[0].id).trigger('change'); // auto-select if only one loan
    }

    $('#loanGroup').toggle(loans.length > 0);
    $('#loanInfo').hide();
    $('#repayAmount').val('').removeAttr('max');
});

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
