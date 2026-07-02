<?php
// config.php - Database configuration
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

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =============================================
// DATABASE CONNECTION
// =============================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// DATABASE CLASS WITH fetchDBQuery METHOD
class Database {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function fetchDBQuery($sql, $params = [], $single = false) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            if ($single) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " SQL: " . $sql);
            return $single ? null : [];
        }
    }
    
    /**
     * Execute a query without fetching results (INSERT, UPDATE, DELETE)
     */
    public function executeQuery($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch(PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " SQL: " . $sql);
            return 0;
        }
    }
    
    /**
     * Get the last inserted ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Get the PDO connection object
     */
    public function getConnection() {
        return $this->pdo;
    }
}

// Create database object
$db = new Database($pdo);

// =============================================
// HELPER FUNCTIONS (Defined ONCE here)
// =============================================

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function formatCurrency($amount) {
    return '₹ ' . number_format((float)$amount, 2);
}

function formatNumber($number) {
    return number_format((float)$number, 2);
}

function getDateRangeDisplay($date_filter, $from_date = '', $to_date = '') {
    switch ($date_filter) {
        case 'today': return 'Today';
        case 'week': return 'Last 7 Days';
        case '15days': return 'Last 15 Days';
        case 'month': return 'Last 1 Month';
        case '3months': return 'Last 3 Months';
        case '6months': return 'Last 6 Months';
        case 'year': return 'Last 1 Year';
        case 'custom':
            if (!empty($from_date) && !empty($to_date)) {
                return date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date));
            }
            return 'Custom';
        default: return 'All Time';
    }
}

function getExecutiveNameById($executive_id) {
    global $db;
    if ($executive_id == 0) {
        return 'All Executives';
    }
    try {
        $sql = "SELECT full_name FROM USERS WHERE user_id = :user_id";
        $result = $db->fetchDBQuery($sql, ['user_id' => $executive_id], true);
        return $result ? $result['full_name'] : 'Executive ' . $executive_id;
    } catch(PDOException $e) {
        return 'Executive ' . $executive_id;
    }
}

// Global arrays for dropdowns
$class_array = [
    6 => 'Class 6',
    7 => 'Class 7', 
    8 => 'Class 8',
    9 => 'Class 9',
    10 => 'Class 10',
    11 => 'Class 11',
    12 => 'Class 12'
];

$board_Array = [
    'C' => 'CBSE',
    'I' => 'ICSE',
    'W' => 'WBBSE',
    'K' => 'Cambridge'
];
?>