<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Savings Management';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_savings'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $amount   = (float)($_POST['amount'] ?? 0);
    $type     = $_POST['type'] ?? 'monthly';
    $monthYear= trim($_POST['month_year'] ?? '');
    $desc     = trim($_POST['description'] ?? '');

    if (!$memberId) $errors[] = 'Please select a member.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if ($amount < 5000 && $type === 'monthly') $errors[] = 'Minimum monthly savings is ₦5,000.';

    if (empty($errors)) {
        $pdo->prepare("INSERT INTO savings (member_id, amount, type, month_year, description, recorded_by) VALUES (?,?,?,?,?,?)")
            ->execute([$memberId, $amount, $type, $monthYear ?: null, $desc, $_SESSION['user_id']]);

        addNotification($pdo, $memberId, 'member', 'Savings Recorded',
            formatCurrency($amount) . ' savings recorded for ' . ($monthYear ?: 'flexible'), 'success');

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'savings_recorded', "Member $memberId, Amount: $amount");
        flashMessage('success', 'Savings of ' . formatCurrency($amount) . ' recorded successfully.');
        header('Location: ' . BASE_URL . '/admin/secretary/savings.php');
        exit;
    }
}

$members = $pdo->query("SELECT id, name, mno FROM members WHERE status='active' ORDER BY name")->fetchAll();

$stmt = $pdo->query("SELECT s.*, m.name AS member_name, m.mno, a.name AS recorded_by_name
    FROM savings s JOIN members m ON s.member_id = m.id
    LEFT JOIN admins a ON s.recorded_by = a.id
    ORDER BY s.created_at DESC LIMIT 50");
$savingsList = $stmt->fetchAll();

$totalSavings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-md-4">
        <div class="info-box bg-success mb-3">
            <span class="info-box-icon"><i class="fas fa-piggy-bank"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Coop Savings</span>
                <span class="info-box-number"><?= formatCurrency($totalSavings) ?></span>
            </div>
        </div>

        <div class="card card-success">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Record Savings</h3></div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Member <span class="text-danger">*</span></label>
                        <select name="member_id" class="form-control" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= sanitize($m['mno']) ?> - <?= sanitize($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (&#8358;) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" min="1000" step="100" required>
                        <small class="text-muted">Min monthly: &#8358;5,000</small>
                    </div>
                    <div class="form-group">
                        <label>Savings Type</label>
                        <select name="type" class="form-control">
                            <option value="monthly">Monthly</option>
                            <option value="flexible">Flexible</option>
                            <option value="payroll">Payroll Deduction</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month/Year (YYYY-MM)</label>
                        <input type="month" name="month_year" class="form-control" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" placeholder="Optional note">
                    </div>
                    <button type="submit" name="record_savings" class="btn btn-success btn-block">
                        <i class="fas fa-save mr-2"></i>Record Savings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Recent Savings Records</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>#</th><th>Member</th><th>Amount</th><th>Type</th><th>Month</th><th>Recorded By</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($savingsList)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No savings records yet.</td></tr>
                    <?php else: foreach ($savingsList as $i => $s): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($s['member_name']) ?></strong><br><small><?= sanitize($s['mno']) ?></small></td>
                        <td class="text-success font-weight-bold"><?= formatCurrency($s['amount']) ?></td>
                        <td><span class="badge badge-info"><?= ucfirst($s['type']) ?></span></td>
                        <td><?= $s['month_year'] ?? '-' ?></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
