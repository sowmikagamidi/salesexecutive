<?php
// ajax_admin_dashboard.php
include_once 'config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Get parameters
$executive_filter = isset($_GET['executive_filter']) ? (int)$_GET['executive_filter'] : 0;
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$executive_search = isset($_GET['executive_search']) ? $_GET['executive_search'] : '';
$coupon_search = isset($_GET['coupon_search']) ? $_GET['coupon_search'] : '';

// Get all executives for dropdown with search
function getAllExecutivesList($pdo, $search = '') {
    try {
        $sql = "
            SELECT user_id, full_name as executive_name
            FROM users
            WHERE user_type = 'SE'
            AND is_deleted = 0
            AND user_status = 'A'
        ";
        if (!empty($search)) {
            $sql .= " AND full_name LIKE '%" . $search . "%'";
        }
        $sql .= " ORDER BY full_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Get all coupons for dropdown with search
function getAllCouponsList($pdo, $search = '') {
    try {
        $sql = "
            SELECT coupon_id, coupon_code
            FROM tx_coupon_codes
            WHERE is_active = 1
            AND coupon_code LIKE 'S%'
        ";
        if (!empty($search)) {
            $sql .= " AND coupon_code LIKE '%" . $search . "%'";
        }
        $sql .= " ORDER BY coupon_code";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

$executives_list = getAllExecutivesList($pdo, $executive_search);
$coupons_list = getAllCouponsList($pdo, $coupon_search);

// Build date filter conditions
function getDateCondition($date_filter, $from_date = '', $to_date = '') {
    switch ($date_filter) {
        case 'today':
            return "AND ph.order_date >= CURDATE()";
        case 'week':
            return "AND ph.order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case '15days':
            return "AND ph.order_date >= DATE_SUB(NOW(), INTERVAL 15 DAY)";
        case 'month':
            return "AND ph.order_date >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        case '3months':
            return "AND ph.order_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
        case '6months':
            return "AND ph.order_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
        case 'year':
            return "AND ph.order_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        case 'custom':
            if (!empty($from_date) && !empty($to_date)) {
                return "AND ph.order_date BETWEEN '" . $from_date . " 00:00:00' AND '" . $to_date . " 23:59:59'";
            }
            return "";
        default:
            return "";
    }
}

// Build executive filter condition
function getExecutiveFilterCondition($executive_filter) {
    if ($executive_filter == 0) {
        return "";
    } else {
        return "AND ph.created_by = " . (int)$executive_filter;
    }
}

// Build coupon filter condition
function getCouponFilterCondition($coupon_filter) {
    if ($coupon_filter == 'all') {
        return "";
    } else {
        return "AND ph.coupon_id = " . (int)$coupon_filter;
    }
}

// Get executive name
function getExecutiveName($pdo, $executive_id) {
    if ($executive_id == 0) return 'All Executives';
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $executive_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['full_name'] : 'All Executives';
    } catch(PDOException $e) {
        return 'All Executives';
    }
}

// Get coupon name
function getCouponName($pdo, $coupon_id) {
    if ($coupon_id == 'all') return 'All Coupons';
    try {
        $stmt = $pdo->prepare("SELECT coupon_code FROM tx_coupon_codes WHERE coupon_id = :id");
        $stmt->execute([':id' => $coupon_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['coupon_code'] : 'All Coupons';
    } catch(PDOException $e) {
        return 'All Coupons';
    }
}

// Get date range display
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

// Function to format number with 2 decimal places
function formatNumber($number) {
    return number_format((float)$number, 2);
}

// Function to get dashboard stats
function getDashboardStats($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $executive_condition = getExecutiveFilterCondition($executive_filter);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        $stmt = $pdo->prepare("
            SELECT COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
        ");
        $stmt->execute();
        $total_sales = $stmt->fetch(PDO::FETCH_ASSOC)['total_sales'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ph.order_id) as total_orders
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
        ");
        $stmt->execute();
        $total_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total_orders'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as total_commission
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
        ");
        $stmt->execute();
        $total_commission = $stmt->fetch(PDO::FETCH_ASSOC)['total_commission'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_executives
            FROM users
            WHERE user_type = 'SE'
            AND is_deleted = 0
        ");
        $stmt->execute();
        $total_executives = $stmt->fetch(PDO::FETCH_ASSOC)['total_executives'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ph.created_by) as active_executives
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
        ");
        $stmt->execute();
        $active_executives = $stmt->fetch(PDO::FETCH_ASSOC)['active_executives'] ?? 0;
        
        return [
            'total_sales' => (float)$total_sales,
            'total_orders' => (int)$total_orders,
            'total_commission' => (float)$total_commission,
            'total_executives' => (int)$total_executives,
            'active_executives' => (int)$active_executives
        ];
    } catch(PDOException $e) {
        return [
            'total_sales' => 0,
            'total_orders' => 0,
            'total_commission' => 0,
            'total_executives' => 0,
            'active_executives' => 0
        ];
    }
}

// Function to get sales overview data
function getSalesOverview($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $executive_condition = getExecutiveFilterCondition($executive_filter);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(ph.order_date, '%d %b') as date_label,
                COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as sales,
                COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as commission
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
            GROUP BY DATE(ph.order_date)
            ORDER BY ph.order_date ASC
            LIMIT 30
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get sales by discount type (Percentage vs Flat)
function getSalesByDiscountType($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $executive_condition = getExecutiveFilterCondition($executive_filter);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        $stmt = $pdo->prepare("
            SELECT 
                cc.discount_type,
                COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
                COUNT(DISTINCT ph.order_id) as order_count
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
            GROUP BY cc.discount_type
            ORDER BY total_sales DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_sales = array_sum(array_column($results, 'total_sales'));
        
        foreach ($results as &$row) {
            $row['percentage'] = $total_sales > 0 ? round(($row['total_sales'] / $total_sales) * 100, 0) : 0;
            $row['label'] = $row['discount_type'] == 'percentage' ? 'Percentage (%)' : 'Flat (₹)';
        }
        
        return $results;
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get sales by subscription type
function getSalesBySubscriptionType($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $executive_condition = getExecutiveFilterCondition($executive_filter);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        $stmt = $pdo->prepare("
            SELECT 
                ph.subscription_type,
                COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
                COUNT(DISTINCT ph.order_id) as order_count
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
            GROUP BY ph.subscription_type
            ORDER BY total_sales DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_sales = array_sum(array_column($results, 'total_sales'));
        
        foreach ($results as &$row) {
            $row['percentage'] = $total_sales > 0 ? round(($row['total_sales'] / $total_sales) * 100, 0) : 0;
            $row['label'] = $row['subscription_type'] == 'B' ? 'Basic (B)' : ($row['subscription_type'] == 'P' ? 'Pro (P)' : 'Demo (D)');
        }
        
        return $results;
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get executives
function getTopExecutives($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        if ($executive_filter > 0) {
            $sql = "
                SELECT 
                    u.user_id,
                    u.full_name as executive_name,
                    COUNT(DISTINCT ph.order_id) as total_orders,
                    COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
                    COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as total_commission,
                    COUNT(DISTINCT cc.coupon_id) as active_coupons
                FROM users u
                LEFT JOIN tx_purchase_history ph ON u.user_id = ph.created_by 
                    AND ph.payment_status = 'S' 
                    AND ph.amount > 0
                    $date_condition
                    $coupon_condition
                LEFT JOIN tx_coupon_codes cc ON u.user_id = cc.created_by AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
                WHERE u.user_type = 'SE'
                AND u.is_deleted = 0
                AND u.user_id = " . (int)$executive_filter . "
                GROUP BY u.user_id
            ";
        } else {
            $sql = "
                SELECT 
                    u.user_id,
                    u.full_name as executive_name,
                    COUNT(DISTINCT ph.order_id) as total_orders,
                    COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
                    COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as total_commission,
                    COUNT(DISTINCT cc.coupon_id) as active_coupons
                FROM users u
                LEFT JOIN tx_purchase_history ph ON u.user_id = ph.created_by 
                    AND ph.payment_status = 'S' 
                    AND ph.amount > 0
                    $date_condition
                    $coupon_condition
                LEFT JOIN tx_coupon_codes cc ON u.user_id = cc.created_by AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
                WHERE u.user_type = 'SE'
                AND u.is_deleted = 0
                GROUP BY u.user_id
                HAVING total_sales > 0 OR active_coupons > 0
                ORDER BY total_sales DESC
                LIMIT 5
            ";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get recent orders
function getRecentOrders($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $executive_condition = getExecutiveFilterCondition($executive_filter);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        $sql = "
            SELECT 
                ph.order_id,
                u.full_name as student_name,
                ex.full_name as executive_name,
                cc.coupon_code,
                ROUND(ph.order_amount, 2) as amount,
                DATE_FORMAT(ph.order_date, '%d %b %Y') as order_date_formatted
            FROM tx_purchase_history ph
            LEFT JOIN users u ON ph.student_id = u.user_id
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            LEFT JOIN users ex ON ph.created_by = ex.user_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.coupon_code LIKE 'S%'
            $date_condition
            $executive_condition
            $coupon_condition
            ORDER BY ph.order_date DESC
            LIMIT 5
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get top performing coupons
function getTopCoupons($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $executive_condition = getExecutiveFilterCondition($executive_filter);
        $coupon_condition = getCouponFilterCondition($coupon_filter);
        
        $sql = "
            SELECT 
                cc.coupon_code,
                COUNT(ph.order_id) as total_used,
                COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
                COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as total_commission
            FROM tx_coupon_codes cc
            LEFT JOIN tx_purchase_history ph ON cc.coupon_id = ph.coupon_id 
                AND ph.payment_status = 'S' 
                AND ph.amount > 0
                $date_condition
                $executive_condition
                $coupon_condition
            WHERE cc.is_active = 1
            AND cc.coupon_code LIKE 'S%'
            GROUP BY cc.coupon_id
            ORDER BY total_sales DESC
            LIMIT 5
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// ===== GET ALL DATA =====
$stats = getDashboardStats($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
$sales_overview = getSalesOverview($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
$subscription_sales = getSalesBySubscriptionType($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
$discount_sales = getSalesByDiscountType($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
$top_executives = getTopExecutives($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
$recent_orders = getRecentOrders($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);
$top_coupons = getTopCoupons($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);

// Get names
$executive_name = getExecutiveName($pdo, $executive_filter);
$coupon_name = getCouponName($pdo, $coupon_filter);

// Return JSON response
$response = [
    'success' => true,
    'stats' => $stats,
    'chart_data' => $sales_overview,
    'subscription_sales' => $subscription_sales,
    'discount_sales' => $discount_sales,
    'executives' => $top_executives,
    'orders' => $recent_orders,
    'coupons' => $top_coupons,
    'date_display' => getDateRangeDisplay($date_filter, $from_date, $to_date),
    'executive_id' => $executive_filter,
    'executive_name' => $executive_name,
    'coupon_id' => $coupon_filter,
    'coupon_name' => $coupon_name
];

echo json_encode($response);
?>