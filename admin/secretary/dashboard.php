<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Secretary Dashboard';

$totalMembers      = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn();
$pendingLoans      = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='pending'")->fetchColumn();
$totalSavingsMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings WHERE month_year = '" . date('Y-m') . "'")->fetchColumn();
$pendingWithdrawals= (int)$pdo->query("SELECT COUNT(*) FROM savings_withdrawals WHERE status='pending'")->fetchColumn();

// Recent loan applications
$recentLoans = $pdo->query("SELECT l.*, m.name AS member_name, m.mno FROM loans l
    JOIN members m ON l.member_id = m.id
    ORDER BY l.applied_at DESC LIMIT 8")->fetchAll();

// Recent members
$recentMembers = $pdo->query("SELECT * FROM members ORDER BY created_at DESC LIMIT 5")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-lg-3 col-6">
        <div class="info-box bg-primary">
            <span class="info-box-icon"><i class="fas fa-users"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Active Members</span>
                <span class="info-box-number"><?= $totalMembers ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="info-box bg-warning">
            <span class="info-box-icon"><i class="fas fa-file-invoice-dollar"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Pending Loans</span>
                <span class="info-box-number"><?= $pendingLoans ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="info-box bg-success">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">This Month Savings</span>
                <span class="info-box-number"><?= formatCurrency($totalSavingsMonth) ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="info-box bg-danger">
            <span class="info-box-icon"><i class="fas fa-money-check-alt"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Pending Withdrawals</span>
                <span class="info-box-number"><?= $pendingWithdrawals ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row">
    <div class="col-12">
        <div class="card card-info">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3></div>
            <div class="card-body">
                <a href="<?= BASE_URL ?>/admin/secretary/members.php?action=add" class="btn btn-primary mr-2 mb-2">
                    <i class="fas fa-user-plus mr-1"></i>Add Member
                </a>
                <a href="<?= BASE_URL ?>/admin/secretary/savings.php?action=record" class="btn btn-success mr-2 mb-2">
                    <i class="fas fa-plus-circle mr-1"></i>Record Savings
                </a>
                <a href="<?= BASE_URL ?>/admin/secretary/loans.php" class="btn btn-warning mr-2 mb-2">
                    <i class="fas fa-file-invoice-dollar mr-1"></i>Review Loans
                </a>
                <a href="<?= BASE_URL ?>/admin/secretary/payroll.php" class="btn btn-info mr-2 mb-2">
                    <i class="fas fa-file-export mr-1"></i>Export Payroll
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list mr-2"></i>Recent Loan Applications</h3>
                <div class="card-tools"><a href="<?= BASE_URL ?>/admin/secretary/loans.php" class="btn btn-sm btn-tool"><i class="fas fa-external-link-alt"></i></a></div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light"><tr><th>Member</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLoans as $loan): ?>
                    <tr>
                        <td><?= sanitize($loan['member_name']) ?><br><small class="text-muted"><?= sanitize($loan['mno']) ?></small></td>
                        <td><?= loanTypeName($loan['loan_type']) ?></td>
                        <td><?= formatCurrency($loan['amount']) ?></td>
                        <td><?= statusBadge($loan['status']) ?></td>
                        <td><?= date('M d, Y', strtotime($loan['applied_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentLoans)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No loan applications.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-user-plus mr-2"></i>Recent Members</h3></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php foreach ($recentMembers as $m): ?>
                <li class="list-group-item py-2">
                    <strong><?= sanitize($m['name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($m['mno']) ?> | <?= sanitize($m['department']) ?></small>
                </li>
                <?php endforeach; ?>
                </ul>
            </div>
            <div class="card-footer">
                <a href="<?= BASE_URL ?>/admin/secretary/members.php" class="btn btn-sm btn-default btn-block">View All Members</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
