<?php
// Load email config; define safe defaults if file is missing or not yet configured
if (file_exists(__DIR__ . '/../config/email.php')) {
    require_once __DIR__ . '/../config/email.php';
}
if (!defined('MAIL_HOST'))      define('MAIL_HOST',      'localhost');
if (!defined('MAIL_PORT'))      define('MAIL_PORT',      25);
if (!defined('MAIL_USERNAME'))  define('MAIL_USERNAME',  '');
if (!defined('MAIL_PASSWORD'))  define('MAIL_PASSWORD',  '');
if (!defined('MAIL_SECURE'))    define('MAIL_SECURE',    'tls');
if (!defined('MAIL_FROM'))      define('MAIL_FROM',      'noreply@mdcan.local');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'MDCAN Cooperative');
if (!defined('MAIL_ENABLED'))   define('MAIL_ENABLED',   false);

/**
 * Send an HTML email.
 * Uses PHPMailer (SMTP) if vendor/phpmailer/src/PHPMailer.php exists,
 * falls back to PHP mail(), and always logs to logs/email.log.
 */
function sendMdcanEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $logLine = date('[Y-m-d H:i:s]') . " TO: $toEmail | SUBJ: $subject";

    if (!MAIL_ENABLED) {
        file_put_contents("$logDir/email.log", $logLine . " [DISABLED]\n", FILE_APPEND);
        return true;
    }

    $phpmailerPath = __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';

    if (file_exists($phpmailerPath)) {
        $result = _sendViaPhpMailer($toEmail, $toName, $subject, $htmlBody);
    } else {
        $result = _sendViaMail($toEmail, $toName, $subject, $htmlBody);
    }

    $status = $result ? '[OK]' : '[FAIL]';
    file_put_contents("$logDir/email.log", $logLine . " $status\n", FILE_APPEND);
    return $result;
}

function _sendViaPhpMailer(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
    require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SECURE === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>','<p>','</p>'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log("Mailer error: " . $e->getMessage());
        return false;
    }
}

function _sendViaMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $to = "\"$toName\" <$toEmail>";
    return mail($to, $subject, $htmlBody, $headers);
}

// ── Email Templates ────────────────────────────────────────────────────────────

function emailWrapper(string $title, string $body): string {
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
  .wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
  .hdr{background:#1a3a5c;color:#fff;padding:25px 30px;text-align:center}
  .hdr h2{margin:0;font-size:22px}
  .hdr small{color:#c9a84c;font-size:13px}
  .body{padding:30px}
  .body p{color:#444;line-height:1.7;margin:0 0 12px}
  .info-box{background:#f0f6ff;border-left:4px solid #1a3a5c;padding:15px 20px;border-radius:4px;margin:20px 0}
  .info-box p{margin:4px 0;color:#333}
  .btn{display:inline-block;padding:12px 28px;background:#1a3a5c;color:#fff!important;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:10px}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:bold}
  .badge-success{background:#d4edda;color:#155724}
  .badge-danger{background:#f8d7da;color:#721c24}
  .ftr{background:#f8f8f8;padding:15px 30px;text-align:center;color:#999;font-size:12px;border-top:1px solid #eee}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr"><h2>&#129309; MDCAN Cooperative</h2><small>Cooperative Management System</small></div>
  <div class="body">$body</div>
  <div class="ftr">This is an automated message from MDCAN Cooperative System &mdash; Please do not reply.</div>
</div>
</body></html>
HTML;
}

function emailNewApplicationToSecretary(array $member, string $secretaryName): string {
    $body = "
    <p>Dear <strong>$secretaryName</strong>,</p>
    <p>A new membership application has been submitted and requires your review.</p>
    <div class='info-box'>
        <p><strong>Applicant Name:</strong> {$member['name']}</p>
        <p><strong>Department:</strong> {$member['department']}</p>
        <p><strong>GSM:</strong> {$member['gsm']}</p>
        <p><strong>Email:</strong> {$member['email']}</p>
        <p><strong>Bank:</strong> {$member['bank_name']}</p>
        <p><strong>Account No:</strong> {$member['account_number']}</p>
        <p><strong>Next of Kin:</strong> {$member['next_of_kin']} ({$member['next_of_kin_gsm']})</p>
        <p><strong>Applied:</strong> " . date('M d, Y H:i') . "</p>
    </div>
    <p>Please log in to review and forward this application to the Director if satisfied.</p>
    <a href='" . BASE_URL . "/admin/secretary/members.php?tab=pending' class='btn'>Review Application</a>";
    return emailWrapper('New Membership Application', $body);
}

function emailForwardedToDirector(array $member, string $directorName): string {
    $body = "
    <p>Dear <strong>$directorName</strong>,</p>
    <p>A membership application reviewed by the Secretary is awaiting your final approval.</p>
    <div class='info-box'>
        <p><strong>Applicant Name:</strong> {$member['name']}</p>
        <p><strong>Department:</strong> {$member['department']}</p>
        <p><strong>GSM:</strong> {$member['gsm']}</p>
        <p><strong>Email:</strong> {$member['email']}</p>
        <p><strong>Bank:</strong> {$member['bank_name']}</p>
        <p><strong>Account No:</strong> {$member['account_number']}</p>
    </div>
    <p>Please log in to approve or decline this application.</p>
    <a href='" . BASE_URL . "/admin/director/members.php?tab=pending' class='btn'>Review Application</a>";
    return emailWrapper('Membership Application – Awaiting Your Approval', $body);
}

function emailMemberApproved(array $member): string {
    $body = "
    <p>Dear <strong>{$member['name']}</strong>,</p>
    <p>Congratulations! &#127881; Your membership application to the <strong>MDCAN Cooperative</strong> has been <span class='badge badge-success'>APPROVED</span>.</p>
    <div class='info-box'>
        <p><strong>Your Member Number (MNO):</strong> <span style='font-size:18px;color:#1a3a5c;font-weight:bold'>{$member['mno']}</span></p>
        <p><strong>Name:</strong> {$member['name']}</p>
        <p><strong>Department:</strong> {$member['department']}</p>
        <p><strong>Approved:</strong> " . date('M d, Y') . "</p>
    </div>
    <p>You can now log in to the Member Portal using your email and the password you set during registration.</p>
    <a href='" . BASE_URL . "/auth/login.php' class='btn'>Login to Member Portal</a>
    <p style='margin-top:20px;color:#666;font-size:13px'>Please keep your Member Number (MNO) safe. You will need it for all cooperative transactions.</p>";
    return emailWrapper('Membership Approved – Welcome to MDCAN Cooperative!', $body);
}

function emailMemberRejected(array $member, string $reason): string {
    $body = "
    <p>Dear <strong>{$member['name']}</strong>,</p>
    <p>We regret to inform you that your membership application to the <strong>MDCAN Cooperative</strong> has been <span class='badge badge-danger'>NOT APPROVED</span>.</p>
    <div class='info-box'>
        <p><strong>Reason for Rejection:</strong></p>
        <p style='color:#721c24'>" . htmlspecialchars($reason) . "</p>
    </div>
    <p>If you believe this decision was made in error or you wish to re-apply after addressing the stated reason, please contact the Cooperative Secretary.</p>
    <p>Thank you for your interest in MDCAN Cooperative.</p>";
    return emailWrapper('Membership Application – Not Approved', $body);
}
