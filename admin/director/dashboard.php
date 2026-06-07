<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Director Dashboard';

// Stats
$stmt = $pdo->query("SELECT COUNT(*) FROM members WHERE status='active'"); $totalMembers = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM loans WHERE status='pending'"); $pendingLoans = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM loans WHERE status='under_review'"); $reviewLoans = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM loans WHERE status='approved'"); $approvedLoans = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings"); $totalSavings = (float)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM loans WHERE status IN ('disbursed','repaying')"); $totalDisbursed = (float)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM savings_withdrawals WHERE status='under_review'"); $pendingWithdrawals = (int)$stmt->fetchColumn();

// Shares summary: per-member savings as share of total
$sharesData = $pdo->query("SELECT m.mno, m.name,
    COALESCE(SUM(s.amount),0) AS member_savings
    FROM members m
    LEFT JOIN savings s ON s.member_id = m.id
    WHERE m.status = 'active'
    GROUP BY m.id
    HAVING member_savings > 0
    ORDER BY member_savings DESC LIMIT 10")->fetchAll();

// Last dividend generation
$lastDividend = $pdo->query("SELECT dr.*, a.name AS generated_by_name
    FROM dividend_records dr JOIN admins a ON dr.generated_by = a.id
    ORDER BY dr.generated_at DESC LIMIT 1")->fetch();

// Loans awaiting director approval
$loansPending = $pdo->query("SELECT l.*, m.name AS member_name, m.mno, m.department
    FROM loans l JOIN members m ON l.member_id = m.id
    WHERE l.status = 'under_review'
    ORDER BY l.applied_at ASC LIMIT 10")->fetchAll();

// Recent audit logs
$auditLogs = $pdo->query("SELECT al.*, COALESCE(m.name, a.name) AS user_name
    FROM audit_logs al
    LEFT JOIN members m ON al.user_id = m.id AND al.user_type = 'member'
    LEFT JOIN admins a ON al.user_id = a.id AND al.user_type = 'admin'
    ORDER BY al.created_at DESC LIMIT 8")->fetchAll();

// Handle approval / decline
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($loanId && in_array($action, ['approve','decline'], true)) {
        $status = $action === 'approve' ? 'approved' : 'declined';
        $stmt = $pdo->prepare("UPDATE loans SET status=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='under_review'");
        $stmt->execute([$status, $_SESSION['user_id'], $loanId]);

        // Get loan owner
        $loan = $pdo->prepare("SELECT member_id, loan_type, amount FROM loans WHERE id=?");
        $loan->execute([$loanId]);
        $loanData = $loan->fetch();

        if ($loanData) {
            $msg = $action === 'approve'
                ? 'Your ' . loanTypeName($loanData['loan_type']) . ' application of ' . formatCurrency($loanData['amount']) . ' has been APPROVED.'
                : 'Your ' . loanTypeName($loanData['loan_type']) . ' application of ' . formatCurrency($loanData['amount']) . ' was declined.';
            addNotification($pdo, $loanData['member_id'], 'member', 'Loan ' . ucfirst($action) . 'd', $msg,
                $action === 'approve' ? 'success' : 'danger', BASE_URL . '/member/loans.php');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', "loan_$action", "Loan ID $loanId $action'd by director");
        flashMessage('success', "Loan #$loanId has been {$action}d.");
        header('Location: ' . BASE_URL . '/admin/director/dashboard.php');
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Stats Row -->
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
            <span class="info-box-icon"><i class="fas fa-clock"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Awaiting Approval</span>
                <span class="info-box-number"><?= $reviewLoans ?></span>
            </div>
        </div>
    </div>
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
        <div class="info-box bg-danger">
            <span class="info-box-icon"><i class="fas fa-hand-holding-usd"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Disbursed Loans</span>
                <span class="info-box-number"><?= formatCurrency($totalDisbursed) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Loans Awaiting Approval -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-warning">
                <h3 class="card-title text-white"><i class="fas fa-gavel mr-2"></i>Loans Awaiting Your Approval</h3>
                <div class="card-tools">
                    <a href="<?= BASE_URL ?>/admin/director/loans.php" class="btn btn-sm btn-light">View All</a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($loansPending)): ?>
                <div class="p-4 text-center text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><br>No loans awaiting approval.</div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>Member</th><th>Type</th><th>Amount</th><th>Duration</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($loansPending as $loan): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($loan['member_name']) ?></strong><br>
                            <small class="text-muted"><?= sanitize($loan['mno']) ?> | <?= sanitize($loan['department']) ?></small>
                        </td>
                        <td><?= loanTypeName($loan['loan_type']) ?></td>
                        <td class="font-weight-bold text-primary"><?= formatCurrency($loan['amount']) ?></td>
                        <td><?= $loan['duration_months'] ?> months</td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                                <button type="submit" name="action" value="approve" class="btn btn-xs btn-success btn-confirm" data-confirm="Approve this loan of <?= formatCurrency($loan['amount']) ?>?">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button type="submit" name="action" value="decline" class="btn btn-xs btn-danger btn-confirm" data-confirm="Decline this loan application?">
                                    <i class="fas fa-times"></i> Decline
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Summary & Audit -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Loan Summary</h3></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between"><span>Pending Review</span><span class="badge badge-warning"><?= $pendingLoans ?></span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>Under Review</span><span class="badge badge-info"><?= $reviewLoans ?></span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>Approved</span><span class="badge badge-success"><?= $approvedLoans ?></span></li>
                    <li class="list-group-item d-flex justify-content-between"><span>Pending Withdrawals</span><span class="badge badge-danger"><?= $pendingWithdrawals ?></span></li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Activity</h3></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" style="max-height:300px;overflow-y:auto">
                <?php foreach ($auditLogs as $log): ?>
                <div class="list-group-item list-group-item-action py-2">
                    <small class="d-flex justify-content-between">
                        <strong><?= sanitize($log['user_name'] ?? 'System') ?></strong>
                        <span class="text-muted"><?= timeAgo($log['created_at']) ?></span>
                    </small>
                    <small class="text-muted"><?= sanitize($log['action']) ?></small>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer text-center">
                <a href="<?= BASE_URL ?>/admin/director/audit_logs.php" class="btn btn-sm btn-default">View All Logs</a>
            </div>
        </div>
    </div>
</div>

<!-- ── Shares Summary ──────────────────────────────────────────────────────── -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-chart-pie mr-2"></i>Members' Shares Summary
                    <small class="ml-2" style="font-size:13px">(Shares = Savings Balance)</small>
                </h3>
                <span class="badge badge-light">Top 10</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($sharesData)): ?>
                <div class="p-4 text-center text-muted">No savings recorded yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>MN No</th>
                            <th>Member</th>
                            <th>Share Value (Savings)</th>
                            <th>% of Total</th>
                            <th>Share Bar</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sharesData as $i => $s):
                        $pct = $totalSavings > 0 ? ($s['member_savings'] / $totalSavings) * 100 : 0;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($s['mno']) ?></strong></td>
                        <td><?= sanitize($s['name']) ?></td>
                        <td class="font-weight-bold text-success"><?= formatCurrency($s['member_savings']) ?></td>
                        <td><span class="badge badge-info"><?= number_format($pct, 2) ?>%</span></td>
                        <td style="min-width:120px">
                            <div class="progress" style="height:10px">
                                <div class="progress-bar bg-success" style="width:<?= min(100, $pct) ?>%"
                                    title="<?= number_format($pct, 2) ?>%"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="3" class="text-right">Total Cooperative Shares:</td>
                            <td class="text-success"><?= formatCurrency($totalSavings) ?></td>
                            <td>100%</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-percentage mr-2"></i>Dividend History</h3></div>
            <div class="card-body">
                <?php if ($lastDividend): ?>
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last Dividend (<?= $lastDividend['year'] ?>)</span>
                        <span class="info-box-number"><?= formatCurrency($lastDividend['appropriated_amount']) ?></span>
                        <span class="progress-description">
                            <?= $lastDividend['members_count'] ?> members &bull;
                            <?= date('M d, Y', strtotime($lastDividend['generated_at'])) ?>
                        </span>
                    </div>
                </div>
                <ul class="list-unstyled small text-muted">
                    <li><i class="fas fa-calendar mr-1"></i> Year: <strong><?= $lastDividend['year'] ?></strong></li>
                    <li><i class="fas fa-piggy-bank mr-1"></i> Total Savings at Time: <?= formatCurrency($lastDividend['total_savings']) ?></li>
                    <li><i class="fas fa-users mr-1"></i> Members Shared: <?= $lastDividend['members_count'] ?></li>
                    <li><i class="fas fa-user-shield mr-1"></i> Generated by: <?= sanitize($lastDividend['generated_by_name']) ?></li>
                </ul>
                <?php else: ?>
                <div class="text-center text-muted py-3">
                    <i class="fas fa-percentage fa-2x mb-2"></i><br>
                    No dividend has been generated yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
