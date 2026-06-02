<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Reports';

// Summary stats
$stats = [
    'total_members'    => (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn(),
    'total_savings'    => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings")->fetchColumn(),
    'total_loans'      => (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn(),
    'disbursed_amount' => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loans WHERE status IN ('disbursed','repaying','completed')")->fetchColumn(),
    'repaid_amount'    => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments")->fetchColumn(),
    'pending_loans'    => (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='pending'")->fetchColumn(),
    'active_loans'     => (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('disbursed','repaying')")->fetchColumn(),
];

// Loans by type
$loansByType = $pdo->query("SELECT loan_type, COUNT(*) AS count, SUM(amount) AS total FROM loans GROUP BY loan_type")->fetchAll();

// Monthly savings (last 12 months)
$monthlySavings = $pdo->query("SELECT month_year, SUM(amount) AS total FROM savings WHERE month_year IS NOT NULL GROUP BY month_year ORDER BY month_year DESC LIMIT 12")->fetchAll();

// Top savers
$topSavers = $pdo->query("SELECT m.name, m.mno, m.department, SUM(s.amount) AS total
    FROM savings s JOIN members m ON s.member_id = m.id
    GROUP BY s.member_id ORDER BY total DESC LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3 col-6"><div class="info-box bg-primary"><span class="info-box-icon"><i class="fas fa-users"></i></span><div class="info-box-content"><span class="info-box-text">Active Members</span><span class="info-box-number"><?= $stats['total_members'] ?></span></div></div></div>
    <div class="col-md-3 col-6"><div class="info-box bg-success"><span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span><div class="info-box-content"><span class="info-box-text">Total Savings</span><span class="info-box-number"><?= formatCurrency($stats['total_savings']) ?></span></div></div></div>
    <div class="col-md-3 col-6"><div class="info-box bg-warning"><span class="info-box-icon"><i class="fas fa-hand-holding-usd"></i></span><div class="info-box-content"><span class="info-box-text">Disbursed</span><span class="info-box-number"><?= formatCurrency($stats['disbursed_amount']) ?></span></div></div></div>
    <div class="col-md-3 col-6"><div class="info-box bg-info"><span class="info-box-icon"><i class="fas fa-check-circle"></i></span><div class="info-box-content"><span class="info-box-text">Repaid</span><span class="info-box-number"><?= formatCurrency($stats['repaid_amount']) ?></span></div></div></div>
</div>

<div class="row">
    <!-- Loans by Type -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Loans by Type</h3></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light"><tr><th>Type</th><th>Count</th><th>Total Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($loansByType as $lt): ?>
                    <tr>
                        <td><?= loanTypeName($lt['loan_type']) ?></td>
                        <td><span class="badge badge-primary"><?= $lt['count'] ?></span></td>
                        <td><?= formatCurrency($lt['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Monthly Savings -->
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar mr-2"></i>Monthly Savings (Last 12)</h3></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="thead-light"><tr><th>Month</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthlySavings as $ms): ?>
                    <tr>
                        <td><?= date('M Y', strtotime($ms['month_year'] . '-01')) ?></td>
                        <td><?= formatCurrency($ms['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Savers -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-trophy mr-2"></i>Top 10 Savers</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>#</th><th>Name</th><th>MNO</th><th>Department</th><th>Total Savings</th></tr></thead>
                    <tbody>
                    <?php foreach ($topSavers as $i => $ts): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitize($ts['name']) ?></td>
                        <td><?= sanitize($ts['mno']) ?></td>
                        <td><?= sanitize($ts['department']) ?></td>
                        <td class="text-success font-weight-bold"><?= formatCurrency($ts['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
