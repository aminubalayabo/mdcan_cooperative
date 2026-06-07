<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('member');

$memberId  = $_SESSION['user_id'];
$pageTitle = 'My Loans';
$errors    = [];
$success   = '';

// ── Handle new loan application ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_loan'])) {
    $loanType = $_POST['loan_type'] ?? '';
    $amount   = (float)($_POST['amount'] ?? 0);
    $months   = (int)($_POST['duration_months'] ?? 0);
    $purpose  = trim($_POST['purpose'] ?? '');
    $guarantorId = (int)($_POST['guarantor_id'] ?? 0);

    $validTypes = ['emergency','soft','essential_commodities','minor_tangible','major_tangible'];

    if (!in_array($loanType, $validTypes, true)) $errors[] = 'Invalid loan type.';
    if ($amount <= 0)  $errors[] = 'Amount must be greater than zero.';
    if ($amount > loanTypeMax($loanType)) $errors[] = 'Amount exceeds maximum for this loan type (' . formatCurrency(loanTypeMax($loanType)) . ').';
    [$minM, $maxM] = loanMonthRange($loanType);
    if ($months < $minM || $months > $maxM) $errors[] = "Duration must be between $minM and $maxM months for this loan type.";
    if (loanNeedsGuarantor($loanType) && !$guarantorId) $errors[] = 'A guarantor is required for this loan type.';
    if ($guarantorId === $memberId) $errors[] = 'You cannot be your own guarantor.';

    // Handle payslip upload
    $payslipPath = '';
    if (!empty($_FILES['payslip']['name'])) {
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['payslip']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            $errors[] = 'Payslip must be PDF, JPG, or PNG.';
        } else {
            $filename = 'payslip_' . uniqid() . '.' . $ext;
            $dest = __DIR__ . '/../uploads/payslips/' . $filename;
            if (move_uploaded_file($_FILES['payslip']['tmp_name'], $dest)) {
                $payslipPath = $filename;
            } else {
                $errors[] = 'Failed to upload payslip. Please try again.';
            }
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $interestRate = loanInterestRate($loanType);
            $needsGuarantor = loanNeedsGuarantor($loanType) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO loans (member_id,loan_type,amount,duration_months,interest_rate,purpose,payslip,requires_guarantor) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$memberId, $loanType, $amount, $months, $interestRate, $purpose, $payslipPath ?: null, $needsGuarantor]);
            $loanId = $pdo->lastInsertId();

            if ($needsGuarantor && $guarantorId) {
                $token = generateToken();
                $stmt2 = $pdo->prepare("INSERT INTO loan_guarantors (loan_id,guarantor_member_id,consent_token) VALUES (?,?,?)");
                $stmt2->execute([$loanId, $guarantorId, $token]);

                // Fetch guarantor details for notification + email
                $gRow = $pdo->prepare("SELECT id, name, email FROM members WHERE id=?");
                $gRow->execute([$guarantorId]);
                $gMember = $gRow->fetch();

                if ($gMember) {
                    // In-app notification
                    addNotification($pdo, $gMember['id'], 'member', 'Guarantor Request',
                        $_SESSION['user_name'] . ' has selected you as guarantor for a ' . loanTypeName($loanType) . ' of ' . formatCurrency($amount) . '. Please respond on your dashboard.',
                        'warning', BASE_URL . '/member/dashboard.php');

                    // Email to guarantor
                    require_once __DIR__ . '/../includes/mailer.php';
                    $emailHtml = emailGuarantorRequest(
                        $gMember['name'],
                        $_SESSION['user_name'],
                        loanTypeName($loanType),
                        $amount,
                        $months
                    );
                    sendMdcanEmail($gMember['email'], $gMember['name'], 'Guarantor Request – MDCAN Cooperative', $emailHtml);
                }
            }

            logAudit($pdo, $memberId, 'member', 'loan_applied', "Loan ID $loanId, type $loanType, amount $amount");

            // Notify secretary
            $stmt3 = $pdo->query("SELECT id FROM admins WHERE role='secretary' LIMIT 1");
            $sec = $stmt3->fetch();
            if ($sec) {
                addNotification($pdo, $sec['id'], 'admin', 'New Loan Application',
                    $_SESSION['user_name'] . ' applied for a ' . loanTypeName($loanType) . ' of ' . formatCurrency($amount),
                    'info', BASE_URL . '/admin/secretary/loans.php');
            }

            $pdo->commit();
            flashMessage('success', 'Loan application submitted successfully! Your application is under review.');
            header('Location: ' . BASE_URL . '/member/loans.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'An error occurred. Please try again.';
        }
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT l.*, a.name AS approved_by_name
    FROM loans l
    LEFT JOIN admins a ON l.approved_by = a.id
    WHERE l.member_id = ?
    ORDER BY l.applied_at DESC");
$stmt->execute([$memberId]);
$loans = $stmt->fetchAll();

// Other active members as potential guarantors
$stmt = $pdo->prepare("SELECT id, name, mno FROM members WHERE id != ? AND status = 'active' ORDER BY name");
$stmt->execute([$memberId]);
$otherMembers = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <!-- Apply Form -->
    <div class="col-md-4">
        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Apply for Loan</h3></div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><i class="fas fa-exclamation-circle mr-1"></i><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Loan Type <span class="text-danger">*</span></label>
                        <select name="loan_type" id="loan_type" class="form-control" required>
                            <option value="">-- Select Type --</option>
                            <option value="emergency">Emergency Loan (Max &#8358;200,000 | 1-4 months)</option>
                            <option value="soft">Soft Loan (Max &#8358;500,000 | 1-10 months)</option>
                            <option value="essential_commodities">Essential Commodities (10% profit, no guarantor)</option>
                            <option value="minor_tangible">Minor Tangible (&lt; &#8358;1,000,000)</option>
                            <option value="major_tangible">Major Tangible (&#8358;1M - &#8358;5M)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (&#8358;) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="amount" class="form-control" min="1000" step="100" required>
                        <small class="text-muted" id="amount_hint"></small>
                    </div>
                    <div class="form-group">
                        <label>Duration (months) <span class="text-danger">*</span></label>
                        <input type="number" name="duration_months" id="duration_months" class="form-control" min="1" max="36" required>
                        <small class="text-muted" id="duration_hint"></small>
                    </div>
                    <div class="form-group">
                        <label>Purpose</label>
                        <textarea name="purpose" class="form-control" rows="3" placeholder="Brief description of loan purpose"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Current Payslip <span class="text-danger">*</span></label>
                        <input type="file" name="payslip" class="form-control-file" accept=".pdf,.jpg,.jpeg,.png" required>
                        <small class="text-muted">PDF, JPG, or PNG — required for processing your application</small>
                    </div>
                    <div id="guarantor_section" class="form-group">
                        <label>Guarantor <span class="text-danger">*</span></label>
                        <select name="guarantor_id" class="form-control">
                            <option value="">-- Select Guarantor --</option>
                            <?php foreach ($otherMembers as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= sanitize($m['mno']) ?> - <?= sanitize($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Required for all loans except Essential Commodities</small>
                    </div>
                    <button type="submit" name="apply_loan" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane mr-2"></i>Submit Application
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Loan History -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Loan History</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Type</th><th>Amount</th><th>Duration</th><th>Interest</th><th>Status</th><th>Applied</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($loans)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No loan applications found.</td></tr>
                    <?php else: foreach ($loans as $i => $loan): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= loanTypeName($loan['loan_type']) ?></td>
                        <td><?= formatCurrency($loan['amount']) ?></td>
                        <td><?= $loan['duration_months'] ?> months</td>
                        <td><?= $loan['interest_rate'] > 0 ? $loan['interest_rate'] . '%' : 'None' ?></td>
                        <td><?= statusBadge($loan['status']) ?></td>
                        <td><?= date('M d, Y', strtotime($loan['applied_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Loan Rules Info -->
        <div class="card card-info collapsed-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Loan Rules & Limits</h3>
                <div class="card-tools"><button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-plus"></i></button></div>
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>Type</th><th>Max Amount</th><th>Duration</th><th>Guarantor</th><th>Interest</th></tr></thead>
                    <tbody>
                        <tr><td>Emergency</td><td>&#8358;200,000</td><td>1–4 months</td><td>Required</td><td>None</td></tr>
                        <tr><td>Soft</td><td>&#8358;500,000</td><td>1–10 months</td><td>Required</td><td>None</td></tr>
                        <tr><td>Essential Commodities</td><td>&#8358;500,000</td><td>1–12 months</td><td><span class="badge badge-success">Not Required</span></td><td>10%</td></tr>
                        <tr><td>Minor Tangible</td><td>&lt; &#8358;1,000,000</td><td>1–24 months</td><td>Required</td><td>None</td></tr>
                        <tr><td>Major Tangible</td><td>&#8358;1M – &#8358;5M</td><td>1–36 months</td><td>Required</td><td>None</td></tr>
                    </tbody>
                </table>
                <p class="text-muted small mb-0"><i class="fas fa-info-circle mr-1"></i>No penalty for missed loan repayments.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
