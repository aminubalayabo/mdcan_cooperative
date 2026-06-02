<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('member');

$memberId  = $_SESSION['user_id'];
$pageTitle = 'My Savings';

$totalSavings = getMemberSavingsTotal($pdo, $memberId);

$stmt = $pdo->prepare("SELECT s.*, a.name AS recorded_by_name FROM savings s
    LEFT JOIN admins a ON s.recorded_by = a.id
    WHERE s.member_id = ? ORDER BY s.created_at DESC");
$stmt->execute([$memberId]);
$savings = $stmt->fetchAll();

// Monthly breakdown
$stmt = $pdo->prepare("SELECT month_year, SUM(amount) AS total FROM savings WHERE member_id=? AND month_year IS NOT NULL GROUP BY month_year ORDER BY month_year DESC LIMIT 12");
$stmt->execute([$memberId]);
$monthly = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-4">
        <div class="info-box bg-success mb-3">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Savings</span>
                <span class="info-box-number"><?= formatCurrency($totalSavings) ?></span>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-success text-white">
                <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Monthly Breakdown</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Month</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php if (empty($monthly)): ?>
                    <tr><td colspan="2" class="text-center text-muted">No records</td></tr>
                    <?php else: foreach ($monthly as $m): ?>
                    <tr>
                        <td><?= date('M Y', strtotime($m['month_year'] . '-01')) ?></td>
                        <td><?= formatCurrency($m['total']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-info">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Savings Rules</h3></div>
            <div class="card-body small">
                <ul class="pl-3">
                    <li>Minimum monthly savings: <strong>&#8358;5,000</strong></li>
                    <li>Flexible savings are allowed</li>
                    <li>Savings can be deducted via payroll</li>
                    <li>No penalty for missed savings</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Savings History</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Amount</th><th>Type</th><th>Month</th><th>Description</th><th>Recorded By</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($savings)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No savings recorded yet.</td></tr>
                    <?php else: foreach ($savings as $i => $s): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="text-success font-weight-bold"><?= formatCurrency($s['amount']) ?></td>
                        <td><span class="badge badge-info"><?= ucfirst($s['type']) ?></span></td>
                        <td><?= $s['month_year'] ?? '-' ?></td>
                        <td><?= sanitize($s['description'] ?? '-') ?></td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
