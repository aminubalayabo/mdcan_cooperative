<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('member');

$memberId = $_SESSION['user_id'];
$pageTitle = 'Member Dashboard';

// Stats
$totalSavings = getMemberSavingsTotal($pdo, $memberId);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status IN ('approved','disbursed','repaying')");
$stmt->execute([$memberId]);
$activeLoans = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status='pending'");
$stmt->execute([$memberId]);
$pendingLoans = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM savings_withdrawals WHERE member_id=? AND status='pending'");
$stmt->execute([$memberId]);
$pendingWithdrawals = (int)$stmt->fetchColumn();

// Member info
$stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
$stmt->execute([$memberId]);
$member = $stmt->fetch();

// Recent loans
$stmt = $pdo->prepare("SELECT * FROM loans WHERE member_id=? ORDER BY applied_at DESC LIMIT 5");
$stmt->execute([$memberId]);
$recentLoans = $stmt->fetchAll();

// Recent savings
$stmt = $pdo->prepare("SELECT * FROM savings WHERE member_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$memberId]);
$recentSavings = $stmt->fetchAll();

// Shares
$stmt = $pdo->prepare("SELECT * FROM member_shares WHERE member_id=?");
$stmt->execute([$memberId]);
$shares = $stmt->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stat Cards -->
<div class="row">
    <div class="col-lg-3 col-6">
        <div class="info-box bg-success">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Savings</span>
                <span class="info-box-number"><?= formatCurrency($totalSavings) ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="info-box bg-primary">
            <span class="info-box-icon"><i class="fas fa-hand-holding-usd"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Active Loans</span>
                <span class="info-box-number"><?= $activeLoans ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="info-box bg-warning">
            <span class="info-box-icon"><i class="fas fa-clock"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Pending Loans</span>
                <span class="info-box-number"><?= $pendingLoans ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="info-box bg-info">
            <span class="info-box-icon"><i class="fas fa-money-bill-wave"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Pending Withdrawals</span>
                <span class="info-box-number"><?= $pendingWithdrawals ?></span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Member Profile -->
    <div class="col-md-4">
        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-user mr-2"></i>My Profile</h3></div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><td><strong>MNO</strong></td><td><?= sanitize($member['mno']) ?></td></tr>
                    <tr><td><strong>Name</strong></td><td><?= sanitize($member['name']) ?></td></tr>
                    <tr><td><strong>Department</strong></td><td><?= sanitize($member['department']) ?></td></tr>
                    <tr><td><strong>GSM</strong></td><td><?= sanitize($member['gsm']) ?></td></tr>
                    <tr><td><strong>Bank</strong></td><td><?= sanitize($member['bank_name']) ?></td></tr>
                    <tr><td><strong>Account No</strong></td><td><?= sanitize($member['account_number']) ?></td></tr>
                    <tr><td><strong>Status</strong></td><td><?= statusBadge($member['status']) ?></td></tr>
                </table>
            </div>
        </div>
        <?php if ($shares): ?>
        <div class="card card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>My Shares</h3></div>
            <div class="card-body text-center">
                <h3><?= number_format($shares['shares_count']) ?> shares</h3>
                <p class="text-muted">@ <?= formatCurrency($shares['value_per_share']) ?> per share</p>
                <h4 class="text-success">Total: <?= formatCurrency($shares['shares_count'] * $shares['value_per_share']) ?></h4>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Loans -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title"><i class="fas fa-file-invoice-dollar mr-2"></i>Recent Loan Applications</h3>
                <a href="<?= BASE_URL ?>/member/loans.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Type</th><th>Amount</th><th>Duration</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentLoans)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No loan applications yet. <a href="<?= BASE_URL ?>/member/loans.php">Apply now</a></td></tr>
                    <?php else: foreach ($recentLoans as $loan): ?>
                    <tr>
                        <td><?= loanTypeName($loan['loan_type']) ?></td>
                        <td><?= formatCurrency($loan['amount']) ?></td>
                        <td><?= $loan['duration_months'] ?> months</td>
                        <td><?= statusBadge($loan['status']) ?></td>
                        <td><?= date('M d, Y', strtotime($loan['applied_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title"><i class="fas fa-piggy-bank mr-2"></i>Recent Savings</h3>
                <a href="<?= BASE_URL ?>/member/savings.php" class="btn btn-sm btn-success">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Amount</th><th>Type</th><th>Month</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentSavings)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No savings recorded yet.</td></tr>
                    <?php else: foreach ($recentSavings as $s): ?>
                    <tr>
                        <td><?= formatCurrency($s['amount']) ?></td>
                        <td><span class="badge badge-secondary"><?= ucfirst($s['type']) ?></span></td>
                        <td><?= $s['month_year'] ?? '-' ?></td>
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
