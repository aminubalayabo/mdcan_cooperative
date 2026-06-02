<?php
ob_start(); // Prevent "headers already sent" caused by BOMs or whitespace in included files

define('DB_HOST', 'localhost');
define('DB_NAME', 'mdcan_cooperative');
define('DB_USER', 'root');
define('DB_PASS', '');

define('BASE_URL', 'http://localhost/mdcan_cooperative');
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
