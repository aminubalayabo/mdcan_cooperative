<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Loan Management';

// ── Forward loan to Director (as-is or adjusted) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_loan'])) {
    $loanId           = (int)($_POST['loan_id'] ?? 0);
    $adjustedAmount   = (float)($_POST['adjusted_amount'] ?? 0);
    $adjustedDuration = (int)($_POST['adjusted_duration'] ?? 0);
    $reviewNotes      = trim($_POST['review_notes'] ?? '');

    if ($loanId) {
        if ($adjustedAmount > 0 && $adjustedDuration > 0) {
            $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW(), amount=?, duration_months=? WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $adjustedAmount, $adjustedDuration, $loanId]);
        } elseif ($adjustedAmount > 0) {
            $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW(), amount=? WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $adjustedAmount, $loanId]);
        } elseif ($adjustedDuration > 0) {
            $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW(), duration_months=? WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $adjustedDuration, $loanId]);
        } else {
            $pdo->prepare("UPDATE loans SET status='under_review', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
                ->execute([$_SESSION['user_id'], $loanId]);
        }

        $adjParts = [];
        if ($adjustedAmount   > 0) $adjParts[] = 'Amount → ' . formatCurrency($adjustedAmount);
        if ($adjustedDuration > 0) $adjParts[] = 'Duration → ' . $adjustedDuration . ' months';
        $adjStr = $adjParts ? ' (Adjusted: ' . implode(', ', $adjParts) . ')' : '';

        $loanRow = $pdo->prepare("SELECT l.amount, l.loan_type, m.name AS mname FROM loans l JOIN members m ON l.member_id=m.id WHERE l.id=?");
        $loanRow->execute([$loanId]);
        $lr = $loanRow->fetch();

        if ($lr) {
            logAudit($pdo, $_SESSION['user_id'], 'admin', 'loan_forwarded',
                "Loan #$loanId forwarded.$adjStr" . ($reviewNotes ? " Note: $reviewNotes" : ''));
            $directors = $pdo->query("SELECT * FROM admins WHERE role='director' AND is_active=1")->fetchAll();
            foreach ($directors as $dir) {
                addNotification($pdo, $dir['id'], 'admin', 'Loan Awaiting Approval',
                    $lr['mname'] . "'s " . loanTypeName($lr['loan_type']) . ' of ' . formatCurrency($lr['amount']) . ' awaiting your approval.' . ($adjParts ? ' (Secretary adjusted)' : ''),
                    'warning', BASE_URL . '/admin/director/loans.php');
            }
        }
        flashMessage('success', "Loan #$loanId forwarded to Director.$adjStr");
        header('Location: ' . BASE_URL . '/admin/secretary/loans.php?filter=under_review');
        exit;
    }
}

// ── Record individual loan payment ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $amount = (float)($_POST['pay_amount'] ?? 0);
    $date   = $_POST['pay_date'] ?? date('Y-m-d');
    $method = $_POST['pay_method'] ?? 'payroll_deduction';

    if ($loanId && $amount > 0) {
        $pdo->prepare("INSERT INTO loan_payments (loan_id, amount, payment_date, payment_method, recorded_by) VALUES (?,?,?,?,?)")
            ->execute([$loanId, $amount, $date, $method, $_SESSION['user_id']]);

        $stmt = $pdo->prepare("SELECT amount FROM loans WHERE id=?");
        $stmt->execute([$loanId]);
        $totalAmount = (float)$stmt->fetchColumn();
        $repaid = getLoanRepaidAmount($pdo, $loanId);

        if ($repaid >= $totalAmount && $totalAmount > 0) {
            $pdo->prepare("UPDATE loans SET status='completed' WHERE id=?")->execute([$loanId]);
        } elseif ($repaid > 0) {
            $pdo->prepare("UPDATE loans SET status='repaying' WHERE id=? AND status='disbursed'")->execute([$loanId]);
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'payment_recorded', "Loan #$loanId, Amount: $amount");
        flashMessage('success', "Payment of " . formatCurrency($amount) . " recorded for Loan #$loanId.");
        header('Location: ' . BASE_URL . '/admin/secretary/loans.php?filter=repaying');
        exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['pending','under_review','approved','disbursed','repaying','completed','all'];
if (!in_array($filter, $validFilters)) $filter = 'pending';

$where  = $filter === 'all' ? '' : "WHERE l.status = ?";
$params = $filter === 'all' ? [] : [$filter];

$stmt = $pdo->prepare("SELECT l.*, m.name AS member_name, m.mno, m.department, m.gsm,
    COALESCE((SELECT SUM(amount) FROM loan_payments lp WHERE lp.loan_id = l.id), 0) AS repaid,
    ROUND(l.amount / l.duration_months, 2) AS monthly_instalment,
    a.name AS reviewed_by_name,
    lg.id AS lg_id, lg.consent_status AS guarantor_consent, lg.notes AS guarantor_notes,
    gm.name AS guarantor_name, gm.mno AS guarantor_mno
    FROM loans l
    JOIN members m ON l.member_id = m.id
    LEFT JOIN admins a ON l.reviewed_by = a.id
    LEFT JOIN loan_guarantors lg ON lg.loan_id = l.id
    LEFT JOIN members gm ON gm.id = lg.guarantor_member_id
    $where ORDER BY l.applied_at DESC");
$stmt->execute($params);
$loans = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filter Tabs -->
<div class="card mb-0">
    <div class="card-header p-2">
        <ul class="nav nav-pills flex-wrap">
            <?php foreach (['pending'=>'Pending','under_review'=>'Under Review','approved'=>'Approved','disbursed'=>'Disbursed','repaying'=>'Repaying','completed'=>'Completed','all'=>'All'] as $f => $lbl): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="card mt-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th><th>Member</th><th>Type</th><th>Amount</th><th>Monthly</th>
                    <th>Repaid / Bal</th><th>Duration</th><th>Status</th><th>Applied</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($loans)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No loans found.</td></tr>
            <?php else: foreach ($loans as $i => $loan):
                $balance = max(0, $loan['amount'] - $loan['repaid']);
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <strong><?= sanitize($loan['member_name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($loan['mno']) ?></small>
                </td>
                <td><small><?= loanTypeName($loan['loan_type']) ?></small></td>
                <td class="font-weight-bold"><?= formatCurrency($loan['amount']) ?></td>
                <td><small class="text-info"><?= formatCurrency($loan['monthly_instalment']) ?>/m</small></td>
                <td>
                    <small class="text-success"><?= formatCurrency($loan['repaid']) ?></small><br>
                    <small class="text-danger">Bal: <?= formatCurrency($balance) ?></small>
                </td>
                <td><?= $loan['duration_months'] ?>m</td>
                <td>
                    <?= statusBadge($loan['status']) ?>
                    <?php if ($loan['requires_guarantor'] && $loan['guarantor_name']): ?>
                    <?php
                        $gc = $loan['guarantor_consent'] ?? 'pending';
                        $gcColor = ['pending' => 'warning', 'accepted' => 'success', 'declined' => 'danger'][$gc] ?? 'secondary';
                    ?>
                    <br><small><span class="badge badge-<?= $gcColor ?>">
                        <i class="fas fa-handshake mr-1"></i>Guarantor: <?= ucfirst($gc) ?>
                    </span></small>
                    <?php endif; ?>
                </td>
                <td><small><?= date('M d, Y', strtotime($loan['applied_at'])) ?></small></td>
                <td>
                <?php if (!empty($loan['payslip'])): ?>
                    <a href="<?= BASE_URL ?>/uploads/payslips/<?= urlencode($loan['payslip']) ?>"
                       target="_blank" class="btn btn-xs btn-info mb-1" title="View member's payslip">
                        <i class="fas fa-file-invoice"></i> Payslip
                    </a><br>
                <?php endif; ?>
                <?php if ($loan['status'] === 'pending'): ?>
                    <!-- Option 1: Forward as applied -->
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="forward_loan" value="1">
                        <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-success btn-confirm"
                            data-confirm="Forward <?= sanitize($loan['member_name']) ?>'s loan of <?= formatCurrency($loan['amount']) ?> / <?= $loan['duration_months'] ?>m to Director as applied?">
                            <i class="fas fa-arrow-right"></i> Forward
                        </button>
                    </form>
                    <!-- Option 2: Review & adjust -->
                    <button class="btn btn-xs btn-warning" data-toggle="modal" data-target="#reviewModal-<?= $loan['id'] ?>">
                        <i class="fas fa-edit"></i> Adjust
                    </button>
                <?php elseif (in_array($loan['status'], ['disbursed','repaying'])): ?>
                    <button class="btn btn-xs btn-success" data-toggle="modal" data-target="#paymentModal-<?= $loan['id'] ?>">
                        <i class="fas fa-plus"></i> Payment
                    </button>
                <?php else: ?>
                    <span class="text-muted small">—</span>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ── Per-loan modals (outside the table for valid HTML) ─────────────────── -->
<?php foreach ($loans as $loan):
    $balance = max(0, $loan['amount'] - $loan['repaid']);
?>

<?php if ($loan['status'] === 'pending'): ?>
<!-- Review & Adjust Modal for Loan #<?= $loan['id'] ?> -->
<div class="modal fade" id="reviewModal-<?= $loan['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="forward_loan" value="1">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Review &amp; Adjust Loan</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border mb-3">
                        <strong><?= sanitize($loan['member_name']) ?></strong> &mdash; <?= loanTypeName($loan['loan_type']) ?><br>
                        Applied Amount: <strong><?= formatCurrency($loan['amount']) ?></strong> &nbsp;|&nbsp;
                        Duration: <strong><?= $loan['duration_months'] ?> months</strong><br>
                        Monthly Instalment: <strong class="text-info"><?= formatCurrency($loan['monthly_instalment']) ?>/month</strong>
                        <?php if (!empty($loan['payslip'])): ?>
                        <br><a href="<?= BASE_URL ?>/uploads/payslips/<?= urlencode($loan['payslip']) ?>"
                               target="_blank" class="btn btn-xs btn-info mt-2">
                            <i class="fas fa-file-invoice mr-1"></i>View Payslip
                        </a>
                        <?php else: ?>
                        <br><span class="badge badge-secondary mt-1"><i class="fas fa-exclamation-circle mr-1"></i>No payslip uploaded</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($loan['requires_guarantor']): ?>
                    <?php
                        $gc = $loan['guarantor_consent'] ?? 'pending';
                        $gcColor = ['pending' => 'warning', 'accepted' => 'success', 'declined' => 'danger'][$gc] ?? 'secondary';
                        $gcIcon  = ['pending' => 'clock', 'accepted' => 'check-circle', 'declined' => 'times-circle'][$gc] ?? 'question-circle';
                    ?>
                    <div class="card border-<?= $gcColor ?> mb-3">
                        <div class="card-header bg-<?= $gcColor ?> py-2 <?= $gc === 'pending' ? '' : 'text-white' ?>">
                            <strong><i class="fas fa-handshake mr-1"></i>Guarantor Consent</strong>
                            <span class="float-right badge badge-light"><?= ucfirst($gc) ?></span>
                        </div>
                        <div class="card-body py-2">
                            <?php if ($loan['guarantor_name']): ?>
                            <p class="mb-1"><strong>Guarantor:</strong> <?= sanitize($loan['guarantor_name']) ?>
                                <small class="text-muted">(<?= sanitize($loan['guarantor_mno']) ?>)</small>
                            </p>
                            <?php if ($gc === 'accepted'): ?>
                            <p class="mb-1 text-success"><i class="fas fa-check-circle mr-1"></i>
                                Guarantor has accepted and signed the declaration.
                            </p>
                            <?php elseif ($gc === 'declined'): ?>
                            <p class="mb-1 text-danger"><i class="fas fa-times-circle mr-1"></i>
                                Guarantor has declined this request.
                            </p>
                            <?php else: ?>
                            <p class="mb-1 text-warning"><i class="fas fa-clock mr-1"></i>
                                Awaiting guarantor's response.
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($loan['guarantor_notes'])): ?>
                            <p class="mb-0 small text-muted"><i class="fas fa-comment mr-1"></i>
                                "<?= sanitize($loan['guarantor_notes']) ?>"
                            </p>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="mb-0 text-muted small">No guarantor assigned.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="font-weight-bold">
                            Adjusted Amount (&#8358;)
                            <small class="text-muted font-weight-normal ml-1">— leave blank to keep <?= formatCurrency($loan['amount']) ?></small>
                        </label>
                        <input type="number" name="adjusted_amount" class="form-control" min="1" step="any"
                               placeholder="Leave blank to keep original amount">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">
                            Adjusted Duration (months)
                            <small class="text-muted font-weight-normal ml-1">— leave blank to keep <?= $loan['duration_months'] ?>m</small>
                        </label>
                        <input type="number" name="adjusted_duration" class="form-control" min="1" max="36"
                               placeholder="Leave blank to keep original duration">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Secretary Notes <small class="text-muted font-weight-normal">(optional)</small></label>
                        <textarea name="review_notes" class="form-control" rows="2"
                            placeholder="Reason for adjustment or observations for the Director..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-arrow-right mr-1"></i>Adjust &amp; Forward to Director
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($loan['status'], ['disbursed','repaying'])): ?>
<!-- Payment Modal for Loan #<?= $loan['id'] ?> -->
<div class="modal fade" id="paymentModal-<?= $loan['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="record_payment" value="1">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave mr-2"></i>Record Payment — <?= sanitize($loan['member_name']) ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Outstanding Balance: <strong class="text-danger"><?= formatCurrency($balance) ?></strong></p>
                    <div class="form-group">
                        <label>Amount (&#8358;)</label>
                        <input type="number" name="pay_amount" class="form-control" min="1" max="<?= $balance ?>" step="100" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="pay_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Method</label>
                        <select name="pay_method" class="form-control">
                            <option value="payroll_deduction">Payroll Deduction</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
