<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Payroll Export';

$monthYear = $_GET['month'] ?? date('Y-m');

// Build payroll data
$stmt = $pdo->prepare("SELECT m.id, m.mno, m.name, m.department, m.bank_name, m.account_number,
    COALESCE((SELECT SUM(s.amount) FROM savings s WHERE s.member_id = m.id AND s.month_year = ?), 0) AS savings_this_month,
    COALESCE((SELECT SUM(lp.amount) FROM loan_payments lp JOIN loans l ON lp.loan_id = l.id WHERE l.member_id = m.id AND DATE_FORMAT(lp.payment_date,'%Y-%m') = ?), 0) AS loan_deduction
    FROM members m WHERE m.status = 'active' ORDER BY m.mno");
$stmt->execute([$monthYear, $monthYear]);
$payrollData = $stmt->fetchAll();

$totals = ['savings' => 0, 'loan_deduction' => 0, 'total_deduction' => 0];
foreach ($payrollData as $row) {
    $totals['savings']       += $row['savings_this_month'];
    $totals['loan_deduction'] += $row['loan_deduction'];
    $totals['total_deduction'] += $row['savings_this_month'] + $row['loan_deduction'];
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payroll_' . $monthYear . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['MNO', 'Name', 'Department', 'Bank', 'Account No', 'Savings', 'Loan Deduction', 'Total Deduction']);
    foreach ($payrollData as $row) {
        fputcsv($out, [
            $row['mno'], $row['name'], $row['department'],
            $row['bank_name'], $row['account_number'],
            $row['savings_this_month'], $row['loan_deduction'],
            $row['savings_this_month'] + $row['loan_deduction']
        ]);
    }
    fputcsv($out, ['', '', '', '', 'TOTALS', $totals['savings'], $totals['loan_deduction'], $totals['total_deduction']]);
    fclose($out);

    // Log the export
    $pdo->prepare("INSERT INTO payroll_exports (month_year, total_members, total_savings, total_loan_deductions, exported_by) VALUES (?,?,?,?,?)")
        ->execute([$monthYear, count($payrollData), $totals['savings'], $totals['loan_deduction'], $_SESSION['user_id']]);
    logAudit($pdo, $_SESSION['user_id'], 'admin', 'payroll_exported', "Month: $monthYear");
    exit;
}

$exportHistory = $pdo->query("SELECT pe.*, a.name AS exported_by_name FROM payroll_exports pe LEFT JOIN admins a ON pe.exported_by = a.id ORDER BY pe.exported_at DESC LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title"><i class="fas fa-file-export mr-2"></i>Payroll for <?= date('F Y', strtotime($monthYear . '-01')) ?></h3>
                <div>
                    <form class="d-inline mr-2">
                        <input type="month" name="month" class="form-control form-control-sm d-inline" style="width:160px" value="<?= $monthYear ?>">
                        <button type="submit" class="btn btn-sm btn-primary">View</button>
                    </form>
                    <a href="?month=<?= $monthYear ?>&export=csv" class="btn btn-sm btn-success">
                        <i class="fas fa-download mr-1"></i>Export CSV
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>#</th><th>MNO</th><th>Name</th><th>Dept</th><th>Bank / Acct</th><th>Savings</th><th>Loan Deduction</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($payrollData)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No payroll data for this month.</td></tr>
                    <?php else: foreach ($payrollData as $i => $r):
                        $total = $r['savings_this_month'] + $r['loan_deduction']; ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($r['mno']) ?></strong></td>
                        <td><?= sanitize($r['name']) ?></td>
                        <td><small><?= sanitize($r['department']) ?></small></td>
                        <td><small><?= sanitize($r['bank_name']) ?><br><?= sanitize($r['account_number']) ?></small></td>
                        <td class="text-success"><?= formatCurrency($r['savings_this_month']) ?></td>
                        <td class="text-warning"><?= formatCurrency($r['loan_deduction']) ?></td>
                        <td class="font-weight-bold"><?= formatCurrency($total) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($payrollData)): ?>
                    <tfoot class="thead-light font-weight-bold">
                        <tr>
                            <td colspan="5" class="text-right">TOTALS (<?= count($payrollData) ?> members)</td>
                            <td class="text-success"><?= formatCurrency($totals['savings']) ?></td>
                            <td class="text-warning"><?= formatCurrency($totals['loan_deduction']) ?></td>
                            <td><?= formatCurrency($totals['total_deduction']) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Export History</h3></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Month</th><th>Members</th><th>By</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($exportHistory as $h): ?>
                    <tr>
                        <td><?= date('M Y', strtotime($h['month_year'] . '-01')) ?></td>
                        <td><?= $h['total_members'] ?></td>
                        <td><small><?= sanitize($h['exported_by_name'] ?? '-') ?></small></td>
                        <td><small><?= date('M d', strtotime($h['exported_at'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-info">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>This Month Summary</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-7">Active Members</dt><dd class="col-5"><?= count($payrollData) ?></dd>
                    <dt class="col-7">Total Savings</dt><dd class="col-5 text-success"><?= formatCurrency($totals['savings']) ?></dd>
                    <dt class="col-7">Loan Deductions</dt><dd class="col-5 text-warning"><?= formatCurrency($totals['loan_deduction']) ?></dd>
                    <dt class="col-7">Grand Total</dt><dd class="col-5 font-weight-bold"><?= formatCurrency($totals['total_deduction']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
