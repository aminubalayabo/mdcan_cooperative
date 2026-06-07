<?php
ob_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'mdcan_cooperative');
define('DB_USER', 'root');
define('DB_PASS', '');

// Auto-detect protocol and host (includes port, e.g. localhost:8080)
$_proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $_proto . '://' . $_host . '/mdcan_cooperative');
unset($_proto, $_host);

define('APP_NAME', 'MDCAN Cooperative System');
define('APP_VERSION', '1.0.0');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<h3>Database connection failed. Please check your configuration.</h3>');
}
