<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Member Management';
$errors    = [];
$action    = $_GET['action'] ?? 'list';
$editMember = null;

if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $editMember = $stmt->fetch();
    if (!$editMember) { flashMessage('danger', 'Member not found.'); header('Location: ' . BASE_URL . '/admin/secretary/members.php'); exit; }
}

// ── Save member (add or edit) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_member'])) {
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $dept        = trim($_POST['department'] ?? '');
    $gsm         = trim($_POST['gsm'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $bankName    = trim($_POST['bank_name'] ?? '');
    $accountNo   = trim($_POST['account_number'] ?? '');
    $nok         = trim($_POST['next_of_kin'] ?? '');
    $nokGsm      = trim($_POST['next_of_kin_gsm'] ?? '');
    $regDate     = $_POST['registration_date'] ?? date('Y-m-d');
    $status      = $_POST['status'] ?? 'active';
    $password    = $_POST['password'] ?? '';

    if (!$name)  $errors[] = 'Name is required.';
    if (!$email) $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        if ($id) {
            // Update
            $fields = "name=?, department=?, gsm=?, email=?, bank_name=?, account_number=?, next_of_kin=?, next_of_kin_gsm=?, registration_date=?, status=?";
            $params = [$name, $dept, $gsm, $email, $bankName, $accountNo, $nok, $nokGsm, $regDate, $status];
            if ($password) {
                $fields .= ", password=?";
                $params[] = password_hash($password, PASSWORD_BCRYPT);
            }
            $params[] = $id;
            $pdo->prepare("UPDATE members SET $fields WHERE id=?")->execute($params);
            logAudit($pdo, $_SESSION['user_id'], 'admin', 'member_updated', "Member ID: $id");
            flashMessage('success', 'Member updated successfully.');
        } else {
            // Insert
            $mno = generateMNO($pdo);
            $pwd = password_hash($password ?: 'mdcan2024', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO members (mno,name,department,gsm,email,password,bank_name,account_number,next_of_kin,next_of_kin_gsm,registration_date,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$mno, $name, $dept, $gsm, $email, $pwd, $bankName, $accountNo, $nok, $nokGsm, $regDate, $status]);
            $newId = $pdo->lastInsertId();

            // Create shares record
            $pdo->prepare("INSERT INTO member_shares (member_id) VALUES (?)")->execute([$newId]);

            logAudit($pdo, $_SESSION['user_id'], 'admin', 'member_added', "New member: $name, MNO: $mno");
            flashMessage('success', "Member added. MNO: $mno. Default password: mdcan2024");
        }
        header('Location: ' . BASE_URL . '/admin/secretary/members.php');
        exit;
    }
}

// ── Toggle status ─────────────────────────────────────────────────────────────
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("UPDATE members SET status = CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=?")->execute([$id]);
    flashMessage('success', 'Member status updated.');
    header('Location: ' . BASE_URL . '/admin/secretary/members.php');
    exit;
}

$members = $pdo->query("SELECT m.*,
    (SELECT COALESCE(SUM(amount),0) FROM savings s WHERE s.member_id = m.id) AS total_savings
    FROM members m ORDER BY m.created_at DESC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <!-- Form -->
    <div class="col-md-4">
        <div class="card card-<?= $editMember ? 'warning' : 'primary' ?>">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-<?= $editMember ? 'edit' : 'user-plus' ?> mr-2"></i>
                    <?= $editMember ? 'Edit Member' : 'Add New Member' ?>
                </h3>
            </div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $editMember['id'] ?? '' ?>">
                    <?php
                    $v = function($field) use ($editMember) { return sanitize($editMember[$field] ?? ''); };
                    $fields = [
                        ['name', 'Full Name *', 'text'],
                        ['department', 'Department', 'text'],
                        ['gsm', 'GSM (Phone)', 'text'],
                        ['email', 'Email *', 'email'],
                        ['bank_name', 'Bank Name', 'text'],
                        ['account_number', 'Account Number', 'text'],
                        ['next_of_kin', 'Next of Kin', 'text'],
                        ['next_of_kin_gsm', 'Next of Kin GSM', 'text'],
                    ];
                    foreach ($fields as [$fname, $label, $type]): ?>
                    <div class="form-group mb-2">
                        <label class="small mb-1"><?= $label ?></label>
                        <input type="<?= $type ?>" name="<?= $fname ?>" class="form-control form-control-sm" value="<?= $v($fname) ?>" <?= str_contains($label, '*') ? 'required' : '' ?>>
                    </div>
                    <?php endforeach; ?>
                    <div class="form-group mb-2">
                        <label class="small mb-1">Registration Date</label>
                        <input type="date" name="registration_date" class="form-control form-control-sm" value="<?= $v('registration_date') ?: date('Y-m-d') ?>">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small mb-1">Status</label>
                        <select name="status" class="form-control form-control-sm">
                            <?php foreach (['active','inactive','suspended'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($editMember['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small mb-1">Password <?= $editMember ? '(leave blank to keep)' : '(default: mdcan2024)' ?></label>
                        <input type="password" name="password" class="form-control form-control-sm" placeholder="<?= $editMember ? 'Leave blank' : 'mdcan2024' ?>">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="save_member" class="btn btn-sm btn-<?= $editMember ? 'warning' : 'primary' ?> flex-fill">
                            <i class="fas fa-save mr-1"></i><?= $editMember ? 'Update' : 'Add Member' ?>
                        </button>
                        <?php if ($editMember): ?>
                        <a href="<?= BASE_URL ?>/admin/secretary/members.php" class="btn btn-sm btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- List -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Members (<?= count($members) ?>)</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>MNO</th><th>Name</th><th>Dept</th><th>GSM</th><th>Savings</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($members)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No members yet.</td></tr>
                    <?php else: foreach ($members as $m): ?>
                    <tr>
                        <td><strong><?= sanitize($m['mno']) ?></strong></td>
                        <td><?= sanitize($m['name']) ?></td>
                        <td><small><?= sanitize($m['department']) ?></small></td>
                        <td><small><?= sanitize($m['gsm']) ?></small></td>
                        <td class="text-success small"><?= formatCurrency($m['total_savings']) ?></td>
                        <td><?= statusBadge($m['status']) ?></td>
                        <td>
                            <a href="?action=edit&id=<?= $m['id'] ?>" class="btn btn-xs btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="?toggle_status=1&id=<?= $m['id'] ?>" class="btn btn-xs btn-<?= $m['status'] === 'active' ? 'secondary' : 'success' ?> btn-confirm"
                               data-confirm="Toggle status for <?= sanitize($m['name']) ?>?" title="Toggle Status">
                               <i class="fas fa-toggle-<?= $m['status'] === 'active' ? 'on' : 'off' ?>"></i>
                            </a>
                        </td>
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
