<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('member');

$memberId = $_SESSION['user_id'];
$pageTitle = 'Member Dashboard';

// ── Guarantor consent submission ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guarantor_consent'])) {
    $lgId          = (int)($_POST['lg_id'] ?? 0);
    $consentAction = $_POST['consent_action'] ?? '';
    $consentNotes  = trim($_POST['consent_notes'] ?? '');

    if ($lgId && in_array($consentAction, ['accepted', 'declined'], true)) {
        // Verify the request belongs to this member and is still pending
        $chk = $pdo->prepare("SELECT lg.*, l.loan_type, l.amount, l.duration_months, l.member_id AS borrower_id,
            m.name AS borrower_name FROM loan_guarantors lg
            JOIN loans l ON lg.loan_id = l.id
            JOIN members m ON l.member_id = m.id
            WHERE lg.id = ? AND lg.guarantor_member_id = ? AND lg.consent_status = 'pending'");
        $chk->execute([$lgId, $memberId]);
        $lg = $chk->fetch();

        if ($lg) {
            $pdo->prepare("UPDATE loan_guarantors SET consent_status=?, consented_at=NOW(), notes=? WHERE id=?")
                ->execute([$consentAction, $consentNotes ?: null, $lgId]);

            // Notify secretary
            $secs = $pdo->query("SELECT id FROM admins WHERE role='secretary' AND is_active=1")->fetchAll();
            foreach ($secs as $sec) {
                addNotification($pdo, $sec['id'], 'admin',
                    'Guarantor ' . ucfirst($consentAction),
                    $_SESSION['user_name'] . ' has ' . $consentAction . ' the guarantor request for ' . $lg['borrower_name'] . "'s " . loanTypeName($lg['loan_type']) . ' loan.',
                    $consentAction === 'accepted' ? 'success' : 'warning',
                    BASE_URL . '/admin/secretary/loans.php?filter=pending');
            }

            // Notify borrower
            addNotification($pdo, $lg['borrower_id'], 'member',
                'Guarantor ' . ucfirst($consentAction),
                $_SESSION['user_name'] . ' has ' . $consentAction . ' your guarantor request for the ' . loanTypeName($lg['loan_type']) . ' loan.',
                $consentAction === 'accepted' ? 'success' : 'danger',
                BASE_URL . '/member/loans.php');

            logAudit($pdo, $memberId, 'member', 'guarantor_' . $consentAction, "Loan #{$lg['loan_id']}");
            flashMessage('success', 'Your response has been recorded and submitted to the Secretary.');
        }
    }
    header('Location: ' . BASE_URL . '/member/dashboard.php');
    exit;
}

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

// All loans with repayment totals
$stmt = $pdo->prepare("SELECT l.*,
    COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id=l.id), 0) AS total_repaid,
    ROUND(l.amount / l.duration_months, 2) AS monthly_instalment
    FROM loans l WHERE l.member_id=? ORDER BY l.applied_at DESC");
$stmt->execute([$memberId]);
$allLoans = $stmt->fetchAll();

// Loan summary totals (only active/disbursed loans count as outstanding)
$loanSummary = ['total_borrowed' => 0, 'total_repaid' => 0, 'total_balance' => 0];
foreach ($allLoans as $l) {
    if (in_array($l['status'], ['approved','disbursed','repaying','completed'])) {
        $loanSummary['total_borrowed'] += $l['amount'];
        $loanSummary['total_repaid']   += $l['total_repaid'];
        $bal = max(0, $l['amount'] - $l['total_repaid']);
        if ($l['status'] !== 'completed') {
            $loanSummary['total_balance'] += $bal;
        }
    }
}
$recentLoans = array_slice($allLoans, 0, 5);

// Recent savings
$stmt = $pdo->prepare("SELECT * FROM savings WHERE member_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$memberId]);
$recentSavings = $stmt->fetchAll();

// Shares: dynamically derived from savings (shares = savings balance)
$totalCoopSavings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings")->fetchColumn();
$sharePct = $totalCoopSavings > 0 ? ($totalSavings / $totalCoopSavings) * 100 : 0;

// Pending guarantor consent requests for this member
$stmt = $pdo->prepare("SELECT lg.*, l.loan_type, l.amount, l.duration_months, l.applied_at,
    m.name AS borrower_name, m.mno AS borrower_mno, m.department AS borrower_dept
    FROM loan_guarantors lg
    JOIN loans l ON lg.loan_id = l.id
    JOIN members m ON l.member_id = m.id
    WHERE lg.guarantor_member_id = ? AND lg.consent_status = 'pending'
    ORDER BY lg.created_at DESC");
$stmt->execute([$memberId]);
$pendingGuarantorRequests = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stat Cards -->
<div class="row">
    <div class="col-lg-3 col-6">
        <div class="info-box bg-success">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Available Savings</span>
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

<!-- ── Guarantor Consent Requests ────────────────────────────────────────── -->
<?php if (!empty($pendingGuarantorRequests)): ?>
<div class="row">
    <div class="col-12">
        <div class="card card-warning">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-handshake mr-2"></i>
                    Guarantor Request<?= count($pendingGuarantorRequests) > 1 ? 's' : '' ?>
                    <span class="badge badge-danger ml-2"><?= count($pendingGuarantorRequests) ?> pending</span>
                </h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>Borrower</th><th>Loan Type</th><th>Amount</th><th>Duration</th><th>Applied</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingGuarantorRequests as $gr): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($gr['borrower_name']) ?></strong><br>
                            <small class="text-muted"><?= sanitize($gr['borrower_mno']) ?> &mdash; <?= sanitize($gr['borrower_dept']) ?></small>
                        </td>
                        <td><?= loanTypeName($gr['loan_type']) ?></td>
                        <td class="font-weight-bold text-primary"><?= formatCurrency($gr['amount']) ?></td>
                        <td><?= $gr['duration_months'] ?> months</td>
                        <td><small><?= date('M d, Y', strtotime($gr['applied_at'])) ?></small></td>
                        <td>
                            <button class="btn btn-sm btn-warning" data-toggle="modal"
                                data-target="#consentModal-<?= $gr['id'] ?>">
                                <i class="fas fa-pen-alt mr-1"></i>Respond
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Loan Summary ──────────────────────────────────────────────────────── -->
<?php if (!empty($allLoans)): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>My Loan Summary</h3>
        <a href="<?= BASE_URL ?>/member/loans.php" class="btn btn-sm btn-outline-primary">Full Details</a>
    </div>
    <div class="card-body pb-2">
        <!-- Totals row -->
        <div class="row text-center mb-3">
            <div class="col-4">
                <div class="p-3 rounded" style="background:#f0f6ff">
                    <div class="text-muted small">Total Borrowed</div>
                    <div class="font-weight-bold text-primary h5 mb-0"><?= formatCurrency($loanSummary['total_borrowed']) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded" style="background:#f0fff4">
                    <div class="text-muted small">Total Repaid</div>
                    <div class="font-weight-bold text-success h5 mb-0"><?= formatCurrency($loanSummary['total_repaid']) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded" style="background:#fff5f5">
                    <div class="text-muted small">Outstanding Balance</div>
                    <div class="font-weight-bold text-danger h5 mb-0"><?= formatCurrency($loanSummary['total_balance']) ?></div>
                </div>
            </div>
        </div>

        <!-- Per-loan breakdown -->
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr><th>Type</th><th>Amount</th><th>Monthly</th><th>Repaid</th><th>Balance</th><th>Progress</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($allLoans as $l):
                $bal      = max(0, $l['amount'] - $l['total_repaid']);
                $pct      = $l['amount'] > 0 ? min(100, round(($l['total_repaid'] / $l['amount']) * 100)) : 0;
                $barColor = $pct >= 100 ? 'success' : ($pct >= 50 ? 'info' : 'warning');
                if (!in_array($l['status'], ['approved','disbursed','repaying','completed'])) continue;
            ?>
            <tr>
                <td><small><?= loanTypeName($l['loan_type']) ?></small></td>
                <td><?= formatCurrency($l['amount']) ?></td>
                <td><small class="text-info"><?= formatCurrency($l['monthly_instalment']) ?></small></td>
                <td class="text-success"><?= formatCurrency($l['total_repaid']) ?></td>
                <td class="<?= $bal > 0 ? 'text-danger' : 'text-success' ?> font-weight-bold"><?= formatCurrency($bal) ?></td>
                <td style="min-width:100px">
                    <div class="progress" style="height:10px" title="<?= $pct ?>% repaid">
                        <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $pct ?>%</small>
                </td>
                <td><?= statusBadge($l['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

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
        <div class="card card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>My Shares</h3></div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <h3 class="text-success mb-0"><?= formatCurrency($totalSavings) ?></h3>
                    <small class="text-muted">Share Value (= Available Savings)</small>
                </div>
                <div class="progress mb-2" style="height:14px" title="<?= number_format($sharePct, 2) ?>% of cooperative">
                    <div class="progress-bar bg-success" style="width:<?= min(100, $sharePct) ?>%"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Your share: <strong class="text-success"><?= number_format($sharePct, 3) ?>%</strong></span>
                    <span>Total: <?= formatCurrency($totalCoopSavings) ?></span>
                </div>
            </div>
        </div>
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
                    <?php else: foreach ($recentSavings as $s): $isWd = $s['type'] === 'withdrawal'; ?>
                    <tr>
                        <td class="font-weight-bold <?= $isWd ? 'text-danger' : 'text-success' ?>">
                            <?= $isWd ? '-' : '' ?><?= formatCurrency(abs($s['amount'])) ?>
                        </td>
                        <td><span class="badge badge-<?= $isWd ? 'danger' : 'secondary' ?>"><?= ucfirst($s['type']) ?></span></td>
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

<!-- ── Guarantor Consent Modals ────────────────────────────────────────────── -->
<?php foreach ($pendingGuarantorRequests as $gr): ?>
<div class="modal fade" id="consentModal-<?= $gr['id'] ?>" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="fas fa-handshake mr-2"></i>Guarantor Consent Form
                </h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" id="consentForm-<?= $gr['id'] ?>">
                <input type="hidden" name="guarantor_consent" value="1">
                <input type="hidden" name="lg_id" value="<?= $gr['id'] ?>">
                <input type="hidden" name="consent_action" id="consentAction-<?= $gr['id'] ?>" value="">
                <div class="modal-body">

                    <!-- Loan Details -->
                    <div class="alert alert-light border mb-3">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted d-block">Borrower</small>
                                <strong><?= sanitize($gr['borrower_name']) ?></strong>
                                <small class="text-muted"> (<?= sanitize($gr['borrower_mno']) ?>)</small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Department</small>
                                <strong><?= sanitize($gr['borrower_dept']) ?></strong>
                            </div>
                            <div class="col-4 mt-2">
                                <small class="text-muted d-block">Loan Type</small>
                                <strong><?= loanTypeName($gr['loan_type']) ?></strong>
                            </div>
                            <div class="col-4 mt-2">
                                <small class="text-muted d-block">Amount</small>
                                <strong class="text-primary"><?= formatCurrency($gr['amount']) ?></strong>
                            </div>
                            <div class="col-4 mt-2">
                                <small class="text-muted d-block">Duration</small>
                                <strong><?= $gr['duration_months'] ?> months</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Declaration Box -->
                    <div class="card border-warning mb-3">
                        <div class="card-header bg-warning py-2">
                            <strong><i class="fas fa-gavel mr-1"></i>Guarantor Declaration</strong>
                        </div>
                        <div class="card-body py-3">
                            <p class="mb-3 text-dark" style="font-size:15px;line-height:1.7">
                                I, <strong><?= sanitize($_SESSION['user_name']) ?></strong>, hereby declare that,
                                in case of any default by the borrower
                                (<strong><?= sanitize($gr['borrower_name']) ?></strong>),
                                I agree to be <strong>wholly or partially responsible</strong> for the payment of
                                the outstanding loan balance.
                            </p>
                            <div class="custom-control custom-checkbox" id="declCheck-<?= $gr['id'] ?>">
                                <input type="checkbox" class="custom-control-input consent-checkbox"
                                    id="agree-<?= $gr['id'] ?>" data-id="<?= $gr['id'] ?>">
                                <label class="custom-control-label font-weight-bold" for="agree-<?= $gr['id'] ?>">
                                    I have read and understood the declaration above
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Optional Notes -->
                    <div class="form-group">
                        <label class="font-weight-bold">
                            Additional Notes <small class="text-muted font-weight-normal">(optional)</small>
                        </label>
                        <textarea name="consent_notes" class="form-control" rows="2"
                            placeholder="Any remarks you wish to pass to the Secretary..."></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger"
                        onclick="submitConsent(<?= $gr['id'] ?>, 'declined')">
                        <i class="fas fa-ban mr-1"></i>Decline
                    </button>
                    <button type="button" class="btn btn-success accept-btn-<?= $gr['id'] ?>" disabled
                        onclick="submitConsent(<?= $gr['id'] ?>, 'accepted')">
                        <i class="fas fa-check mr-1"></i>Accept &amp; Agree
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function submitConsent(id, action) {
    document.getElementById('consentAction-' + id).value = action;
    document.getElementById('consentForm-' + id).submit();
}

document.querySelectorAll('.consent-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var id = this.dataset.id;
        document.querySelector('.accept-btn-' + id).disabled = !this.checked;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
