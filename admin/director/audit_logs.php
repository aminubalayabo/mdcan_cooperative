<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('director');

$pageTitle = 'Audit Logs';

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;
$total    = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$pages    = ceil($total / $perPage);

$stmt = $pdo->prepare("SELECT al.*, COALESCE(m.name, a.name) AS user_name
    FROM audit_logs al
    LEFT JOIN members m ON al.user_id = m.id AND al.user_type = 'member'
    LEFT JOIN admins a ON al.user_id = a.id AND al.user_type = 'admin'
    ORDER BY al.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$perPage, $offset]);
$logs = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>System Audit Logs</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-light">
                <tr><th>#</th><th>User</th><th>Type</th><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No logs found.</td></tr>
            <?php else: foreach ($logs as $i => $log): ?>
            <tr>
                <td><?= $offset + $i + 1 ?></td>
                <td><?= sanitize($log['user_name'] ?? 'Guest') ?></td>
                <td><span class="badge badge-<?= $log['user_type'] === 'admin' ? 'primary' : 'info' ?>"><?= ucfirst($log['user_type']) ?></span></td>
                <td><?= sanitize($log['action']) ?></td>
                <td class="text-muted small"><?= sanitize(substr($log['details'] ?? '', 0, 60)) ?></td>
                <td class="text-muted small"><?= sanitize($log['ip_address']) ?></td>
                <td class="text-muted small"><?= date('M d Y H:i', strtotime($log['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer">
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
