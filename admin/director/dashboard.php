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
$stmt = $pdo->query("SELECT COUNT(*) FROM savings_withdrawals WHERE status='pending'"); $pendingWithdrawals = (int)$stmt->fetchColumn();

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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
