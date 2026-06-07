<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Savings Management';
$errors    = [];
$csvResults = [];

// ── Bulk CSV Import ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_savings_csv'])) {
    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($handle); // skip header row

        $inserted = 0; $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) { $skipped++; continue; }

            $mno        = trim($row[0] ?? '');
            $amount     = (float)trim($row[1] ?? 0);
            $savType    = strtolower(trim($row[2] ?? 'monthly'));
            $monthYear  = trim($row[3] ?? date('Y-m'));
            $desc       = trim($row[4] ?? '');

            if (!$mno || $amount <= 0) {
                $csvResults[] = ['row' => $mno ?: '(empty)', 'status' => 'error', 'msg' => 'Missing MNO or invalid amount'];
                $skipped++; continue;
            }

            $savType = in_array($savType, ['monthly','flexible','payroll']) ? $savType : 'monthly';

            // Lookup member by MNO
            $ms = $pdo->prepare("SELECT id, name FROM members WHERE mno = ? AND status = 'active'");
            $ms->execute([$mno]);
            $member = $ms->fetch();

            if (!$member) {
                $csvResults[] = ['row' => $mno, 'status' => 'error', 'msg' => "MNO not found or member inactive"];
                $skipped++; continue;
            }

            // Validate month_year format
            if ($monthYear && !preg_match('/^\d{4}-\d{2}$/', $monthYear)) {
                $monthYear = date('Y-m'); // default to current month
            }

            $pdo->prepare("INSERT INTO savings (member_id, amount, type, month_year, description, recorded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$member['id'], $amount, $savType, $monthYear ?: null, $desc, $_SESSION['user_id']]);

            addNotification($pdo, $member['id'], 'member', 'Savings Recorded',
                formatCurrency($amount) . ' savings recorded via bulk import.', 'success');

            $csvResults[] = ['row' => $mno, 'status' => 'success', 'msg' => sanitize($member['name']) . ' — ' . formatCurrency($amount) . ' recorded'];
            $inserted++;
        }
        fclose($handle);

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'bulk_savings_imported', "Inserted: $inserted, Skipped: $skipped");
        flashMessage('success', "Bulk import complete. <strong>$inserted inserted</strong>, $skipped skipped.");
    } else {
        flashMessage('danger', 'Please select a CSV file to upload.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_savings'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $amount   = (float)($_POST['amount'] ?? 0);
    $type     = $_POST['type'] ?? 'monthly';
    $monthYear= trim($_POST['month_year'] ?? '');
    $desc     = trim($_POST['description'] ?? '');

    if (!$memberId) $errors[] = 'Please select a member.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if ($amount < 5000 && $type === 'monthly') $errors[] = 'Minimum monthly savings is ₦5,000.';

    if (empty($errors)) {
        $pdo->prepare("INSERT INTO savings (member_id, amount, type, month_year, description, recorded_by) VALUES (?,?,?,?,?,?)")
            ->execute([$memberId, $amount, $type, $monthYear ?: null, $desc, $_SESSION['user_id']]);

        addNotification($pdo, $memberId, 'member', 'Savings Recorded',
            formatCurrency($amount) . ' savings recorded for ' . ($monthYear ?: 'flexible'), 'success');

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'savings_recorded', "Member $memberId, Amount: $amount");
        flashMessage('success', 'Savings of ' . formatCurrency($amount) . ' recorded successfully.');
        header('Location: ' . BASE_URL . '/admin/secretary/savings.php');
        exit;
    }
}

$members = $pdo->query("SELECT id, name, mno FROM members WHERE status='active' ORDER BY name")->fetchAll();

$stmt = $pdo->query("SELECT s.*, m.name AS member_name, m.mno, a.name AS recorded_by_name
    FROM savings s JOIN members m ON s.member_id = m.id
    LEFT JOIN admins a ON s.recorded_by = a.id
    ORDER BY s.created_at DESC LIMIT 50");
$savingsList = $stmt->fetchAll();

$totalSavings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-single"><i class="fas fa-user mr-1"></i>Record Single</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-bulk"><i class="fas fa-file-csv mr-1"></i>Bulk CSV Import</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-history"><i class="fas fa-list mr-1"></i>History</a></li>
</ul>

<div class="tab-content">

<!-- ── TAB: Single Entry ─────────────────────────────────────────────────── -->
<div class="tab-pane fade show active" id="tab-single">
<div class="row">
    <div class="col-md-4">
        <div class="info-box bg-success mb-3">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Coop Savings</span>
                <span class="info-box-number"><?= formatCurrency($totalSavings) ?></span>
            </div>
        </div>

        <div class="card card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Record Savings</h3></div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Member <span class="text-danger">*</span></label>
                        <select name="member_id" class="form-control" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= sanitize($m['mno']) ?> - <?= sanitize($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (&#8358;) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" min="1000" step="100" required>
                        <small class="text-muted">Min monthly: &#8358;5,000</small>
                    </div>
                    <div class="form-group">
                        <label>Savings Type</label>
                        <select name="type" class="form-control">
                            <option value="monthly">Monthly</option>
                            <option value="flexible">Flexible</option>
                            <option value="payroll">Payroll Deduction</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month/Year (YYYY-MM)</label>
                        <input type="month" name="month_year" class="form-control" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" placeholder="Optional note">
                    </div>
                    <button type="submit" name="record_savings" class="btn btn-success btn-block">
                        <i class="fas fa-save mr-2"></i>Record Savings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Recent Savings Records</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Member</th><th>Amount</th><th>Type</th><th>Month</th><th>Recorded By</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($savingsList)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No savings records yet.</td></tr>
                    <?php else: foreach ($savingsList as $i => $s): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($s['member_name']) ?></strong><br><small><?= sanitize($s['mno']) ?></small></td>
                        <?php $isWd = $s['type'] === 'withdrawal'; ?>
                        <td class="font-weight-bold <?= $isWd ? 'text-danger' : 'text-success' ?>">
                            <?= $isWd ? '-' : '' ?><?= formatCurrency(abs($s['amount'])) ?>
                        </td>
                        <td><span class="badge badge-<?= $isWd ? 'danger' : 'info' ?>"><?= ucfirst($s['type']) ?></span></td>
                        <td><?= $s['month_year'] ?? '-' ?></td>
                        <td><?= sanitize($s['recorded_by_name'] ?? 'System') ?></td>
                        <td><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- end tab-single -->

<!-- ── TAB: Bulk CSV ─────────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="tab-bulk">
<div class="row">
    <div class="col-md-5">
        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-upload mr-2"></i>Bulk Savings CSV Upload</h3></div>
            <div class="card-body">
                <p class="text-muted small mb-3">Upload a CSV file with savings deducted from payroll for multiple members at once.</p>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="font-weight-bold">CSV File <span class="text-danger">*</span></label>
                        <div class="custom-file">
                            <input type="file" name="csv_file" class="custom-file-input" accept=".csv" required id="csvFile">
                            <label class="custom-file-label" for="csvFile">Choose CSV file...</label>
                        </div>
                    </div>
                    <button type="submit" name="bulk_savings_csv" class="btn btn-primary btn-block mt-3">
                        <i class="fas fa-cloud-upload-alt mr-2"></i>Upload &amp; Process
                    </button>
                </form>

                <hr>
                <h6 class="font-weight-bold">Required CSV Format</h6>
                <div class="table-responsive">
                <table class="table table-sm table-bordered small mb-2">
                    <thead class="thead-light"><tr><th>MN No</th><th>Amount</th><th>Savings Type</th><th>Date</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td>MNO-0001</td><td>10000</td><td>monthly</td><td>2026-06</td><td>June deduction</td></tr>
                        <tr><td>MNO-0002</td><td>8000</td><td>payroll</td><td>2026-06</td><td></td></tr>
                    </tbody>
                </table>
                </div>
                <ul class="small text-muted pl-3 mb-0">
                    <li>First row must be a header row (it is skipped)</li>
                    <li><strong>Savings Type</strong>: monthly, flexible, or payroll</li>
                    <li><strong>Date</strong> format: YYYY-MM (e.g. 2026-06)</li>
                    <li>Description column is optional</li>
                </ul>

                <div class="mt-3">
                    <a href="data:text/csv;charset=utf-8,MN%20No%2CAmount%2CSavings%20Type%2CDate%2CDescription%0AMNO-0001%2C10000%2Cmonthly%2C<?= date('Y-m') ?>%2CSalary%20deduction%0A"
                       download="savings_template.csv" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-download mr-1"></i>Download Template
                    </a>
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
                            <i class="fas fa-<?= $r['status'] === 'success' ? 'check-circle text-success' : 'times-circle text-danger' ?>  mr-1"></i>
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
            <i class="fas fa-file-csv fa-3x mb-3"></i><br>
            Upload a CSV file to see results here.
        </div></div>
        <?php endif; ?>
    </div>
</div>
</div><!-- end tab-bulk -->

<!-- ── TAB: History ──────────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="tab-history">
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list mr-2"></i>Recent Savings Records (last 50)</h3>
        <div class="card-tools">
            <span class="badge badge-success">Total: <?= formatCurrency($totalSavings) ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>Member</th><th>Amount</th><th>Type</th><th>Month</th><th>Recorded By</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php if (empty($savingsList)): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">No savings records yet.</td></tr>
            <?php else: foreach ($savingsList as $i => $s): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($s['member_name']) ?></strong><br><small><?= sanitize($s['mno']) ?></small></td>
                <td class="text-success font-weight-bold"><?= formatCurrency($s['amount']) ?></td>
                <td><span class="badge badge-info"><?= ucfirst($s['type']) ?></span></td>
                <td><?= $s['month_year'] ?? '-' ?></td>
                <td><?= sanitize($s['recorded_by_name'] ?? 'System') ?></td>
                <td><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
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
// Show file name in custom file input
$('.custom-file-input').on('change', function() {
    var fileName = $(this).val().split('\\').pop();
    $(this).siblings('.custom-file-label').text(fileName || 'Choose CSV file...');
});
// Keep active tab on CSV result page reload
<?php if (!empty($csvResults)): ?>
$('[href="#tab-bulk"]').tab('show');
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
