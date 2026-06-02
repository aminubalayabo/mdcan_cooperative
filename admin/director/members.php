<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole('director');

$pageTitle = 'Member Management';
$tab = $_GET['tab'] ?? 'pending';

// ── Approve membership ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_member'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);

    // Generate MNO and activate
    $mno = generateMNO($pdo);
    $pdo->prepare("UPDATE members SET mno=?, status='active', forwarded_by=forwarded_by WHERE id=? AND status='pending_director'")
        ->execute([$mno, $memberId]);

    // Create shares entry
    $pdo->prepare("INSERT IGNORE INTO member_shares (member_id) VALUES (?)")->execute([$memberId]);

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if ($member) {
        // Email member with MNO
        sendMdcanEmail(
            $member['email'], $member['name'],
            'Welcome to MDCAN Cooperative – Membership Approved!',
            emailMemberApproved($member)
        );

        // System notification for the member (visible once they log in)
        addNotification($pdo, $memberId, 'member',
            'Membership Approved!',
            'Congratulations! Your membership has been approved. Your MNO is ' . $mno . '. You can now login.',
            'success', BASE_URL . '/auth/login.php');

        // Notify the secretary who forwarded
        if ($member['forwarded_by']) {
            addNotification($pdo, $member['forwarded_by'], 'admin',
                'Membership Approved',
                $member['name'] . "'s membership application has been approved by the Director. MNO: $mno",
                'success');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'membership_approved', "Member: {$member['name']}, MNO: $mno");
        flashMessage('success', $member['name'] . " approved! MNO: $mno. Notification email sent.");
    }

    header('Location: ' . BASE_URL . '/admin/director/members.php?tab=pending');
    exit;
}

// ── Reject membership ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_member'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $reason   = trim($_POST['rejection_reason'] ?? '');

    if (!$reason) {
        flashMessage('danger', 'A reason for rejection is required.');
        header('Location: ' . BASE_URL . '/admin/director/members.php?tab=pending'); exit;
    }

    $pdo->prepare("UPDATE members SET status='rejected', rejection_reason=? WHERE id=? AND status='pending_director'")
        ->execute([$reason, $memberId]);

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if ($member) {
        sendMdcanEmail(
            $member['email'], $member['name'],
            'MDCAN Cooperative – Membership Application Not Approved',
            emailMemberRejected($member, $reason)
        );

        if ($member['forwarded_by']) {
            addNotification($pdo, $member['forwarded_by'], 'admin',
                'Membership Rejected',
                $member['name'] . "'s application was rejected by the Director. Reason: $reason",
                'danger');
        }

        logAudit($pdo, $_SESSION['user_id'], 'admin', 'membership_rejected_by_director', "Member ID: $memberId. Reason: $reason");
        flashMessage('success', $member['name'] . "'s application rejected. Applicant notified by email.");
    }

    header('Location: ' . BASE_URL . '/admin/director/members.php?tab=pending');
    exit;
}

// Data
$pendingMembers = $pdo->query("SELECT m.*, a.name AS forwarded_by_name
    FROM members m LEFT JOIN admins a ON m.forwarded_by = a.id
    WHERE m.status = 'pending_director'
    ORDER BY m.forwarded_at ASC")->fetchAll();

$pendingCount = count($pendingMembers);

$allMembers = $pdo->query("SELECT m.*,
    (SELECT COALESCE(SUM(amount),0) FROM savings s WHERE s.member_id=m.id) AS total_savings,
    (SELECT COUNT(*) FROM loans l WHERE l.member_id=m.id AND l.status IN ('approved','disbursed','repaying')) AS active_loans
    FROM members m
    WHERE m.status NOT IN ('pending_secretary','pending_director')
    ORDER BY m.created_at DESC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='pending'?'active':'' ?>" href="?tab=pending">
            <i class="fas fa-gavel mr-1"></i>Awaiting Approval
            <?php if ($pendingCount): ?>
            <span class="badge badge-danger ml-1"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='all'?'active':'' ?>" href="?tab=all">
            <i class="fas fa-users mr-1"></i>All Members
        </a>
    </li>
</ul>

<!-- ══ PENDING APPROVALS ════════════════════════════════════════════════════ -->
<?php if ($tab === 'pending'): ?>

<?php if (empty($pendingMembers)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
    <i class="fas fa-check-circle fa-3x text-success mb-3"></i><br>
    No membership applications pending your approval.
</div></div>
<?php else: ?>

<?php foreach ($pendingMembers as $m): ?>
<div class="card mb-3 border-left border-warning" style="border-left:4px solid #ffc107 !important">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:#fffbf0">
        <h5 class="card-title mb-0">
            <i class="fas fa-user-circle mr-2 text-warning"></i>
            <?= sanitize($m['name']) ?>
            <span class="badge badge-warning ml-2">Awaiting Your Approval</span>
        </h5>
        <small class="text-muted">
            Reviewed by: <strong><?= sanitize($m['forwarded_by_name'] ?? 'Secretary') ?></strong>
            <?= $m['forwarded_at'] ? ' on ' . date('M d, Y H:i', strtotime($m['forwarded_at'])) : '' ?>
        </small>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <h6 class="text-muted font-weight-bold small text-uppercase mb-2">Personal Details</h6>
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="font-weight-bold text-muted w-40">Full Name</td><td><?= sanitize($m['name']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Department</td><td><?= sanitize($m['department']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">GSM</td><td><?= sanitize($m['gsm']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Email</td><td><?= sanitize($m['email']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Reg. Date</td><td><?= $m['registration_date'] ? date('M d, Y', strtotime($m['registration_date'])) : '-' ?></td></tr>
                </table>
            </div>
            <div class="col-md-4">
                <h6 class="text-muted font-weight-bold small text-uppercase mb-2">Bank Details</h6>
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="font-weight-bold text-muted w-40">Bank</td><td><?= sanitize($m['bank_name']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">Account No</td><td><?= sanitize($m['account_number']) ?></td></tr>
                </table>
                <h6 class="text-muted font-weight-bold small text-uppercase mb-2 mt-3">Next of Kin</h6>
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="font-weight-bold text-muted w-40">Name</td><td><?= sanitize($m['next_of_kin']) ?></td></tr>
                    <tr><td class="font-weight-bold text-muted">GSM</td><td><?= sanitize($m['next_of_kin_gsm']) ?></td></tr>
                </table>
            </div>
            <div class="col-md-4 d-flex flex-column justify-content-center align-items-center">
                <p class="text-muted small text-center mb-3">
                    This application was reviewed and forwarded by the Secretary.<br>
                    Your decision will be emailed to the applicant.
                </p>

                <!-- Approve -->
                <form method="POST" class="w-100 mb-2">
                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                    <button type="submit" name="approve_member" class="btn btn-success btn-block btn-confirm"
                        data-confirm="Approve <?= sanitize($m['name']) ?>'s membership? An MNO will be generated and emailed to them.">
                        <i class="fas fa-check-circle mr-2"></i>Approve Membership
                    </button>
                </form>

                <!-- Reject -->
                <button class="btn btn-outline-danger btn-block" data-toggle="modal" data-target="#rejectModal"
                    data-id="<?= $m['id'] ?>" data-name="<?= sanitize($m['name']) ?>">
                    <i class="fas fa-times-circle mr-2"></i>Reject Application
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ══ ALL MEMBERS ═══════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'all'): ?>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Active / All Members (<?= count($allMembers) ?>)</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>MNO</th><th>Name</th><th>Department</th><th>GSM</th><th>Total Savings</th><th>Active Loans</th><th>Status</th><th>Joined</th></tr>
            </thead>
            <tbody>
            <?php if (empty($allMembers)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No members found.</td></tr>
            <?php else: foreach ($allMembers as $i => $m): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= sanitize($m['mno'] ?? '-') ?></strong></td>
                <td><?= sanitize($m['name']) ?></td>
                <td><?= sanitize($m['department']) ?></td>
                <td><?= sanitize($m['gsm']) ?></td>
                <td class="text-success"><?= formatCurrency($m['total_savings']) ?></td>
                <td><span class="badge badge-primary"><?= $m['active_loans'] ?></span></td>
                <td><?= statusBadge($m['status']) ?></td>
                <td><?= $m['registration_date'] ? date('M Y', strtotime($m['registration_date'])) : '-' ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
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
                    <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i>Reject Membership Application</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>You are rejecting the membership of <strong id="reject_name"></strong>.<br>
                    A rejection email with your stated reason will be sent to the applicant.</p>
                    <div class="form-group">
                        <label><strong>Reason for Rejection <span class="text-danger">*</span></strong></label>
                        <textarea name="rejection_reason" class="form-control" rows="4"
                            placeholder="State clearly why this application is being rejected. This will be sent to the applicant." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_member" class="btn btn-danger"><i class="fas fa-times mr-1"></i>Reject &amp; Notify Applicant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$('#rejectModal').on('show.bs.modal', function(e) {
    var btn = $(e.relatedTarget);
    $('#reject_member_id').val(btn.data('id'));
    $('#reject_name').text(btn.data('name'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
