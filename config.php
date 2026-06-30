<?php
// config.php - Database configuration for tutorix_db
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'tutorix_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

if (!defined('BASEURL')) {
    define('BASEURL', 'http://localhost/salesexecutive');
}

// Session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default session for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 19;
    $_SESSION['user_type'] = 'SE';
    $_SESSION['school_id'] = 1;
}

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Helper functions
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Note: formatCurrency() is now only in the dashboard file to avoid duplication
?>