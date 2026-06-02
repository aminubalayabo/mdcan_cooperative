<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole('secretary');

$pageTitle = 'Member Management';
$errors    = [];
$tab       = $_GET['tab'] ?? 'pending';
$editMember= null;

if (isset($_GET['tab']) && $_GET['tab'] === 'edit' && isset($_GET['id'])) {
    $tab = 'all';
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $editMember = $stmt->fetch();
}

// ── Forward to Director (Secretary approves) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_to_director'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);

    $rowsAffected = 0;
    try {
        $upd = $pdo->prepare("UPDATE members SET status='pending_director', forwarded_by=?, forwarded_at=NOW() WHERE id=? AND status='pending_secretary'");
        $upd->execute([$_SESSION['user_id'], $memberId]);
        $rowsAffected = $upd->rowCount();
    } catch (PDOException $e) {
        $upd2 = $pdo->prepare("UPDATE members SET status='pending_director' WHERE id=? AND status='pending_secretary'");
        $upd2->execute([$memberId]);
        $rowsAffected = $upd2->rowCount();
    }

    if ($rowsAffected === 0) {
        flashMessage('danger', 'Update failed — member may already have been forwarded.');
        header('Location: ' . BASE_URL . '/admin/secretary/members.php?tab=pending');
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if ($member) {
        $directors = $pdo->query("SELECT * FROM admins WHERE role='director' AND is_active=1")->fetchAll();
        foreach ($directors as $dir) {
            addNotification($pdo, $dir['id'], 'admin',
                'Membership Application Ready for Approval',
                $member['name'] . "'s application has been reviewed by the Secretary.",
                'warning', BASE_URL . '/admin/director/members.php?tab=pending');
            try {
                sendMdcanEmail($dir['email'], $dir['name'],
                    'Membership Application Awaiting Your Approval – ' . $member['name'],
                    emailForwardedToDirector($member, $dir['name']));
            } catch (\Exception $e) {
                error_log('Forward email failed: ' . $e->getMessage());
            }
        }
        addNotification($pdo, $memberId, 'member', 'Application Progress',
            'Your application has been forwarded to the Director for final approval.', 'info');
        logAudit($pdo, $_SESSION['user_id'], 'admin', 'membership_forwarded', "Member ID: $memberId");
        flashMessage('success', $member['name'] . "'s application forwarded to the Director.");
    } else {
        flashMessage('danger', 'Member record not found.');
    }

    header('Location: ' . BASE_URL . '/admin/secretary/members.php?tab=pending');
    exit;
}

// ── Secretary rejects application ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_application'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $reason   = trim($_POST['rejection_reason'] ?? '');

    if (!$reason) {
        flashMessage('danger', 'Please provide a reason for rejection.');
        header('Location: ' . BASE_URL . '/admin/secretary/members.php?tab=pending');
        exit;
    }

    $pdo->prepare("UPDATE members SET status='rejected', rejection_reason=? WHERE id=? AND status='pending_secretary'")
        ->execute([$reason, $memberId]);

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if ($member) {
        try {
            sendMdcanEmail(
                $member['email'], $member['name'],
                'MDCAN Cooperative – Membership Application Update',
                emailMemberRejected($member, $reason)
            );
        } catch (\Exception $e) {
            error_log('Rejection email failed: ' . $e->getMessage());
        }
        logAudit($pdo, $_SESSION['user_id'], 'admin', 'membership_rejected_by_secretary', "Member ID: $memberId. Reason: $reason");
        flashMessage('success', $member['name'] . "'s application has been rejected and the applicant notified.");
    }

    header('Location: ' . BASE_URL . '/admin/secretary/members.php?tab=pending');
    exit;
}

// ── Add / Edit member (direct add by secretary) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_member'])) {
    $id        = (int)($_POST['id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $dept      = trim($_POST['department'] ?? '');
    $gsm       = trim($_POST['gsm'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $bankName  = trim($_POST['bank_name'] ?? '');
    $accountNo = trim($_POST['account_number'] ?? '');
    $nok       = trim($_POST['next_of_kin'] ?? '');
    $nokGsm    = trim($_POST['next_of_kin_gsm'] ?? '');
    $regDate   = $_POST['registration_date'] ?? date('Y-m-d');
    $status    = $_POST['status'] ?? 'active';
    $password  = $_POST['password'] ?? '';

    if (!$name)  $errors[] = 'Name is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    if (empty($errors)) {
        if ($id) {
            $fields = "name=?, department=?, gsm=?, email=?, bank_name=?, account_number=?, next_of_kin=?, next_of_kin_gsm=?, registration_date=?, status=?";
            $params = [$name, $dept, $gsm, $email, $bankName, $accountNo, $nok, $nokGsm, $regDate, $status];
            if ($password) { $fields .= ', password=?'; $params[] = password_hash($password, PASSWORD_BCRYPT); }
            $params[] = $id;
            $pdo->prepare("UPDATE members SET $fields WHERE id=?")->execute($params);
            logAudit($pdo, $_SESSION['user_id'], 'admin', 'member_updated', "ID: $id");
            flashMessage('success', 'Member updated.');
        } else {
            // Secretary directly adds an active member (assigning MNO)
            $mno = generateMNO($pdo);
            $pwd = password_hash($password ?: 'mdcan2024', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO members (mno,name,department,gsm,email,password,bank_name,account_number,next_of_kin,next_of_kin_gsm,registration_date,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active')")
                ->execute([$mno, $name, $dept, $gsm, $email, $pwd, $bankName, $accountNo, $nok, $nokGsm, $regDate]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO member_shares (member_id) VALUES (?)")->execute([$newId]);
            logAudit($pdo, $_SESSION['user_id'], 'admin', 'member_added_directly', "MNO: $mno");
            flashMessage('success', "Member added. MNO: $mno. Default password: " . ($password ?: 'mdcan2024'));
        }
        header('Location: ' . BASE_URL . '/admin/secretary/members.php?tab=all');
        exit;
    }
}

// Toggle status
if (isset($_GET['toggle_status'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("UPDATE members SET status = CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=? AND status IN ('active','inactive')")->execute([$id]);
    flashMessage('success', 'Member status updated.');
    header('Location: ' . BASE_URL . '/admin/secretary/members.php?tab=all'); exit;
}

// Data
$pending = $pdo->query("SELECT * FROM members WHERE status='pending_secretary' ORDER BY created_at ASC")->fetchAll();
$allMembers = $pdo->query("SELECT m.*,
    (SELECT COALESCE(SUM(amount),0) FROM savings s WHERE s.member_id=m.id) AS total_savings
    FROM members m WHERE m.status NOT IN ('pending_secretary')
    ORDER BY m.created_at DESC")->fetchAll();
$pendingCount = count($pending);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'pending' ? 'active' : '' ?>" href="?tab=pending">
            <i class="fas fa-clock mr-1"></i>Pending Applications
            <?php if ($pendingCount): ?>
            <span class="badge badge-danger ml-1"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="?tab=all">
            <i class="fas fa-users mr-1"></i>All Members
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'add' ? 'active' : '' ?>" href="?tab=add">
            <i class="fas fa-user-plus mr-1"></i>Add Member
        </a>
    </li>
</ul>

<!-- ══ TAB: PENDING APPLICATIONS ════════════════════════════════════════════ -->
<?php if ($tab === 'pending'): ?>

<?php if (empty($pending)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
    <i class="fas fa-check-circle fa-3x text-success mb-3"></i><br>
    No pending applications. All caught up!
</div></div>
<?php else: ?>

<?php foreach ($pending as $m): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center bg-warning-light" style="background:#fff8e1">
        <h5 class="card-title mb-0">
            <i class="fas fa-user-circle mr-2 text-warning"></i>
            <?= sanitize($m['name']) ?>
            <span class="badge badge-warning ml-2">Awaiting Review</span>
        </h5>
        <small class="text-muted">Applied: <?= date('M d, Y H:i', strtotime($m['created_at'])) ?></small>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="font-weight-bold text-muted" width="45%">Name</td><td><?= sanitize($m['name']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Department</td><td><?= sanitize($m['department']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">GSM</td><td><?= sanitize($m['gsm']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Email</td><td><?= sanitize($m['email']) ?></td></tr>
                </table>
            </div>
            <div class="col-md-4">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="font-weight-bold text-muted" width="45%">Bank</td><td><?= sanitize($m['bank_name']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Account No</td><td><?= sanitize($m['account_number']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Reg. Date</td><td><?= $m['registration_date'] ? date('M d, Y', strtotime($m['registration_date'])) : '-' ?></td></tr>
                </table>
            </div>
            <div class="col-md-4">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="font-weight-bold text-muted" width="45%">Next of Kin</td><td><?= sanitize($m['next_of_kin']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">NOK GSM</td><td><?= sanitize($m['next_of_kin_gsm']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small">Review this application and either forward to Director or reject.</span>
        <div>
            <!-- Forward to Director -->
            <form method="POST" class="d-inline">
                <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                <button type="submit" name="forward_to_director" class="btn btn-success btn-sm btn-confirm"
                    data-confirm="Forward <?= sanitize($m['name']) ?>'s application to the Director?">
                    <i class="fas fa-arrow-right mr-1"></i>Forward to Director
                </button>
            </form>
            <!-- Reject -->
            <button class="btn btn-danger btn-sm ml-2" data-toggle="modal" data-target="#rejectModal"
                data-id="<?= $m['id'] ?>" data-name="<?= sanitize($m['name']) ?>">
                <i class="fas fa-times mr-1"></i>Reject
            </button>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ══ TAB: ALL MEMBERS ══════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'all'): ?>

<div class="row">
    <?php if ($editMember): ?>
    <div class="col-md-4">
        <div class="card card-warning">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-edit mr-2"></i>Edit Member</h3></div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $editMember['id'] ?>">
                    <?php
                    $v = fn($f) => sanitize($editMember[$f] ?? '');
                    $fields = [['name','Full Name *','text'],['department','Department','text'],['gsm','GSM','text'],
                               ['email','Email *','email'],['bank_name','Bank Name','text'],['account_number','Account No','text'],
                               ['next_of_kin','Next of Kin','text'],['next_of_kin_gsm','NOK GSM','text']];
                    foreach ($fields as [$fname,$label,$type]): ?>
                    <div class="form-group mb-2">
                        <label class="small mb-1"><?= $label ?></label>
                        <input type="<?= $type ?>" name="<?= $fname ?>" class="form-control form-control-sm" value="<?= $v($fname) ?>" <?= str_contains($label,'*') ? 'required' : '' ?>>
                    </div>
                    <?php endforeach; ?>
                    <div class="form-group mb-2">
                        <label class="small mb-1">Registration Date</label>
                        <input type="date" name="registration_date" class="form-control form-control-sm" value="<?= $v('registration_date') ?>">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small mb-1">Status</label>
                        <select name="status" class="form-control form-control-sm">
                            <?php foreach (['active','inactive','suspended'] as $s): ?>
                            <option value="<?= $s ?>" <?= $editMember['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small mb-1">New Password (leave blank to keep)</label>
                        <input type="password" name="password" class="form-control form-control-sm" placeholder="Leave blank">
                    </div>
                    <div class="d-flex">
                        <button type="submit" name="save_member" class="btn btn-sm btn-warning flex-fill mr-2"><i class="fas fa-save mr-1"></i>Update</button>
                        <a href="?tab=all" class="btn btn-sm btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
    <?php else: ?>
    <div class="col-12">
    <?php endif; ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Members (<?= count($allMembers) ?>)</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>MNO</th><th>Name</th><th>Dept</th><th>GSM</th><th>Savings</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($allMembers)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No members yet.</td></tr>
                    <?php else: foreach ($allMembers as $m): ?>
                    <tr>
                        <td><strong><?= sanitize($m['mno'] ?? '-') ?></strong></td>
                        <td><?= sanitize($m['name']) ?></td>
                        <td><small><?= sanitize($m['department']) ?></small></td>
                        <td><small><?= sanitize($m['gsm']) ?></small></td>
                        <td class="text-success small"><?= formatCurrency($m['total_savings']) ?></td>
                        <td><?= statusBadge($m['status']) ?></td>
                        <td>
                            <a href="?tab=edit&id=<?= $m['id'] ?>" class="btn btn-xs btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="?tab=all&toggle_status=1&id=<?= $m['id'] ?>"
                               class="btn btn-xs btn-<?= $m['status']==='active'?'secondary':'success' ?> btn-confirm"
                               data-confirm="Toggle status for <?= sanitize($m['name']) ?>?">
                               <i class="fas fa-toggle-<?= $m['status']==='active'?'on':'off' ?>"></i>
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

<!-- ══ TAB: ADD MEMBER ══════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'add'): ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-user-plus mr-2"></i>Add Member Directly (Skip Workflow)</h3></div>
            <div class="card-body">
                <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger py-2 small"><?= sanitize($e) ?></div>
                <?php endforeach; ?>
                <form method="POST">
                    <div class="row">
                        <?php
                        $fields2 = [['name','Full Name *','text'],['department','Department','text'],['gsm','GSM','text'],
                                    ['email','Email *','email'],['bank_name','Bank Name','text'],['account_number','Account No','text'],
                                    ['next_of_kin','Next of Kin','text'],['next_of_kin_gsm','NOK GSM','text']];
                        foreach ($fields2 as [$fname,$label,$type]):
                        ?>
                        <div class="col-md-6">
                            <div class="form-group mb-2">
                                <label class="small mb-1"><?= $label ?></label>
                                <input type="<?= $type ?>" name="<?= $fname ?>" class="form-control form-control-sm" value="<?= sanitize($_POST[$fname] ?? '') ?>" <?= str_contains($label,'*')?'required':'' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="col-md-6">
                            <div class="form-group mb-2">
                                <label class="small mb-1">Registration Date</label>
                                <input type="date" name="registration_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-2">
                                <label class="small mb-1">Password <span class="text-muted">(default: mdcan2024)</span></label>
                                <input type="text" name="password" class="form-control form-control-sm" placeholder="mdcan2024">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-2">
                                <label class="small mb-1">MNO</label>
                                <input type="text" class="form-control form-control-sm bg-light" value="Auto-generated" disabled>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="save_member" class="btn btn-primary btn-block">
                        <i class="fas fa-save mr-2"></i>Add Member
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="member_id" id="reject_member_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i>Reject Application</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>You are rejecting the application of <strong id="reject_member_name"></strong>. The applicant will be notified by email.</p>
                    <div class="form-group">
                        <label><strong>Reason for Rejection <span class="text-danger">*</span></strong></label>
                        <textarea name="rejection_reason" class="form-control" rows="4" placeholder="Please provide a clear reason that will be sent to the applicant..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_application" class="btn btn-danger"><i class="fas fa-times mr-1"></i>Reject & Notify</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$('#rejectModal').on('show.bs.modal', function(e) {
    var btn = $(e.relatedTarget);
    $('#reject_member_id').val(btn.data('id'));
    $('#reject_member_name').text(btn.data('name'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
