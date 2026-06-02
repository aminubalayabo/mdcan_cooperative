<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth helpers ──────────────────────────────────────────────────────────────

function isLoggedIn(): bool {
    return isset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['user_role']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
}

function requireRole(array|string $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], (array)$roles, true)) {
        header('Location: ' . BASE_URL . '/auth/login.php?error=unauthorized');
        exit;
    }
}

function isDirector(): bool  { return ($_SESSION['user_role'] ?? '') === 'director';  }
function isSecretary(): bool { return ($_SESSION['user_role'] ?? '') === 'secretary'; }
function isMember(): bool    { return ($_SESSION['user_role'] ?? '') === 'member';    }
function isAdmin(): bool     { return isDirector() || isSecretary(); }

// ── Output / formatting ───────────────────────────────────────────────────────

function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function formatCurrency(float|string $amount): string {
    return '&#8358;' . number_format((float)$amount, 2);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('M d, Y', strtotime($datetime));
}

function statusBadge(string $status): string {
    $map = [
        'pending'       => 'warning',
        'under_review'  => 'info',
        'approved'      => 'success',
        'declined'      => 'danger',
        'disbursed'     => 'primary',
        'repaying'      => 'primary',
        'completed'     => 'secondary',
        'active'        => 'success',
        'inactive'      => 'secondary',
        'suspended'     => 'danger',
        'processed'     => 'success',
        'accepted'      => 'success',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge badge-' . $color . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

// ── Loan helpers ──────────────────────────────────────────────────────────────

function loanTypeName(string $type): string {
    return [
        'emergency'             => 'Emergency Loan',
        'soft'                  => 'Soft Loan',
        'essential_commodities' => 'Essential Commodities Loan',
        'minor_tangible'        => 'Minor Tangible Loan',
        'major_tangible'        => 'Major Tangible Loan',
    ][$type] ?? ucfirst($type);
}

function loanTypeMax(string $type): float {
    return [
        'emergency'             => 200000,
        'soft'                  => 500000,
        'essential_commodities' => 500000,
        'minor_tangible'        => 999999,
        'major_tangible'        => 5000000,
    ][$type] ?? 0;
}

function loanNeedsGuarantor(string $type): bool {
    return $type !== 'essential_commodities';
}

function loanMonthRange(string $type): array {
    return [
        'emergency'             => [1, 4],
        'soft'                  => [1, 10],
        'essential_commodities' => [1, 12],
        'minor_tangible'        => [1, 24],
        'major_tangible'        => [1, 36],
    ][$type] ?? [1, 12];
}

function loanInterestRate(string $type): float {
    return $type === 'essential_commodities' ? 10.0 : 0.0;
}

// ── Notifications & audit ─────────────────────────────────────────────────────

function addNotification(PDO $pdo, int $userId, string $userType, string $title, string $message, string $type = 'info', string $link = ''): void {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id,user_type,title,message,type,link) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$userId, $userType, $title, $message, $type, $link]);
}

function getUnreadNotifications(PDO $pdo, int $userId, string $userType): array {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND user_type=? AND is_read=0 ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$userId, $userType]);
    return $stmt->fetchAll();
}

function countUnread(PDO $pdo, int $userId, string $userType): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND user_type=? AND is_read=0");
    $stmt->execute([$userId, $userType]);
    return (int)$stmt->fetchColumn();
}

function markNotificationsRead(PDO $pdo, int $userId, string $userType): void {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND user_type=?");
    $stmt->execute([$userId, $userType]);
}

function logAudit(PDO $pdo, int $userId, string $userType, string $action, string $details = ''): void {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id,user_type,action,details,ip_address) VALUES (?,?,?,?,?)");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt->execute([$userId, $userType, $action, $details, $ip]);
}

// ── Misc ──────────────────────────────────────────────────────────────────────

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function generateMNO(PDO $pdo): string {
    $stmt = $pdo->query("SELECT COUNT(*) FROM members");
    $count = (int)$stmt->fetchColumn() + 1;
    return 'MNO-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function getMemberSavingsTotal(PDO $pdo, int $memberId): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM savings WHERE member_id=?");
    $stmt->execute([$memberId]);
    return (float)$stmt->fetchColumn();
}

function getMemberActiveLoans(PDO $pdo, int $memberId): array {
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE member_id=? AND status IN ('approved','disbursed','repaying') ORDER BY applied_at DESC");
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

function getLoanRepaidAmount(PDO $pdo, int $loanId): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE loan_id=?");
    $stmt->execute([$loanId]);
    return (float)$stmt->fetchColumn();
}

function flashMessage(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function renderFlash(): string {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $icon = ['success' => 'check-circle', 'danger' => 'times-circle', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'][$f['type']] ?? 'info-circle';
    return '<div class="alert alert-' . $f['type'] . ' alert-dismissible fade show" role="alert">
        <i class="fas fa-' . $icon . ' mr-2"></i>' . sanitize($f['message']) . '
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>';
}
