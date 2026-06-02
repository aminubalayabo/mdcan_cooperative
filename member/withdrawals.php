<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('member');

$memberId  = $_SESSION['user_id'];
$pageTitle = 'Savings Withdrawals';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_withdrawal'])) {
    $type   = $_POST['withdrawal_type'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    $validTypes = ['account_closure','loan_liquidation','cash_withdrawal'];
    if (!in_array($type, $validTypes, true)) $errors[] = 'Invalid withdrawal type.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';

    $totalSavings = getMemberSavingsTotal($pdo, $memberId);
    if ($amount > $totalSavings) $errors[] = 'Withdrawal amount exceeds your total savings (' . formatCurrency($totalSavings) . ').';

    // Handle document upload
    $docPath = '';
    if (!empty($_FILES['document']['name'])) {
        $allowedExt = ['pdf','jpg','jpeg','png'];
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            $errors[] = 'Document must be PDF, JPG, or PNG.';
        } else {
            $filename = uniqid('doc_') . '.' . $ext;
            $dest = __DIR__ . '/../uploads/documents/' . $filename;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $dest)) {
                $docPath = $filename;
            }
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO savings_withdrawals (member_id,amount,withdrawal_type,reason,supporting_document) VALUES (?,?,?,?,?)");
        $stmt->execute([$memberId, $amount, $type, $reason, $docPath]);

        logAudit($pdo, $memberId, 'member', 'withdrawal_requested', "Amount: $amount, Type: $type");

        $sec = $pdo->query("SELECT id FROM admins WHERE role='secretary' LIMIT 1")->fetch();
        if ($sec) {
            addNotification($pdo, $sec['id'], 'admin', 'Withdrawal Request',
                $_SESSION['user_name'] . ' requested a ' . str_replace('_', ' ', $type) . ' of ' . formatCurrency($amount),
                'warning', BASE_URL . '/admin/secretary/withdrawals.php');
        }

        flashMessage('success', 'Withdrawal request submitted. Awaiting approval.');
        header('Location: ' . BASE_URL . '/member/withdrawals.php');
        exit;
    }
}

$totalSavings = getMemberSavingsTotal($pdo, $memberId);

$stmt = $pdo->prepare("SELECT w.*, a.name AS approved_by_name FROM savings_withdrawals w
    LEFT JOIN admins a ON w.approved_by = a.id
    WHERE w.member_id = ? ORDER BY w.requested_at DESC");
$stmt->execute([$memberId]);
$withdrawals = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-4">
        <div class="info-box bg-primary mb-3">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Available Savings</span>
                <span class="info-box-number"><?= formatCurrency($totalSavings) ?></span>
            </div>
        </div>

        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-plus mr-2"></i>Request Withdrawal</h3></div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Withdrawal Type <span class="text-danger">*</span></label>
                        <select name="withdrawal_type" class="form-control" required>
                            <option value="">-- Select Type --</option>
                            <option value="cash_withdrawal">Cash Withdrawal</option>
                            <option value="loan_liquidation">Liquidation of Loan from Savings</option>
                            <option value="account_closure">Account Closure</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (&#8358;) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" min="1" max="<?= $totalSavings ?>" step="100" required>
                        <small class="text-muted">Max: <?= formatCurrency($totalSavings) ?></small>
                    </div>
                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Reason for withdrawal"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Supporting Document (optional)</label>
                        <input type="file" name="document" class="form-control-file" accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">PDF, JPG, or PNG only</small>
                    </div>
                    <button type="submit" name="request_withdrawal" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane mr-2"></i>Submit Request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Withdrawal History</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Type</th><th>Amount</th><th>Reason</th><th>Status</th><th>Requested</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($withdrawals)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No withdrawal requests found.</td></tr>
                    <?php else: foreach ($withdrawals as $i => $w): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= ucwords(str_replace('_', ' ', $w['withdrawal_type'])) ?></td>
                        <td><?= formatCurrency($w['amount']) ?></td>
                        <td><?= sanitize(substr($w['reason'] ?? '-', 0, 40)) ?></td>
                        <td><?= statusBadge($w['status']) ?></td>
                        <td><?= date('M d, Y', strtotime($w['requested_at'])) ?></td>
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
