<?php
// ============================================================
// MDCAN Email Configuration
// ============================================================
//
// OPTION A – Mailtrap (recommended for LOCAL XAMPP testing)
//   1. Sign up free at https://mailtrap.io
//   2. Go to Email Testing → Inboxes → SMTP Settings
//   3. Copy the credentials below
//
// OPTION B – Gmail (production / shared hosting)
//   1. Enable 2FA on your Google account
//   2. Create an App Password: Google Account → Security → App Passwords
//   3. Use your Gmail address + App Password below
//
// OPTION C – cPanel / Shared Hosting Mail
//   Use your hosting SMTP settings
//
// PHPMailer setup (required for SMTP):
//   Download: https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip
//   Extract and copy these 3 files into vendor/phpmailer/src/:
//     PHPMailer.php, SMTP.php, Exception.php
// ============================================================

define('MAIL_HOST',      'smtp.mailtrap.io');    // Change to your SMTP host
define('MAIL_PORT',      2525);                  // 587 for TLS, 465 for SSL, 2525 for Mailtrap
define('MAIL_USERNAME',  'your_mailtrap_user');  // Your SMTP username
define('MAIL_PASSWORD',  'your_mailtrap_pass');  // Your SMTP password
define('MAIL_SECURE',    'tls');                 // 'tls' or 'ssl'
define('MAIL_FROM',      'noreply@mdcan.edu.ng');
define('MAIL_FROM_NAME', 'MDCAN Cooperative System');

// Set to false to disable real sending and log emails to logs/email.log only
define('MAIL_ENABLED', true);
