<?php
include_once 'config.php';
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
header('Content-Type: application/json');

// Get parameters
$executive_filter = isset($_GET['executive_filter']) ? (int)$_GET['executive_filter'] : 0;
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$executive_search = isset($_GET['executive_search']) ? $_GET['executive_search'] : '';
$coupon_search = isset($_GET['coupon_search']) ? $_GET['coupon_search'] : '';
// FUNCTIONS USING $db ONLY

function getAllExecutivesList($db, $search = '') {
    $sql = "
        SELECT
            user_id,
            full_name AS executive_name
        FROM USERS
        WHERE user_type = 'SE'
        AND is_deleted = 0
        AND user_status = 'A'
    ";
    if (!empty($search)) {
        $sql .= " AND full_name LIKE '%" . $search . "%'";
    }
    $sql .= " ORDER BY full_name";
    
    return $db->fetchDBQuery($sql);
}

// Get all coupons for dropdown with search
function getAllCouponsList($db, $search = '') {
    $sql = "
        SELECT
            coupon_id,
            coupon_code
        FROM TX_COUPON_CODES
        WHERE is_active = 1
        AND coupon_code LIKE 'S%'
    ";
    if (!empty($search)) {
        $sql .= " AND coupon_code LIKE '%" . $search . "%'";
    }
    $sql .= " ORDER BY coupon_code";
    
    return $db->fetchDBQuery($sql);
}

$executives_list = getAllExecutivesList($db, $executive_search);
$coupons_list = getAllCouponsList($db, $coupon_search);

// Build date filter conditions
function getDateCondition($date_filter, $from_date = '', $to_date = '') {
    switch ($date_filter) {
        case 'today':
            return "AND DATE(ph.order_date) = CURDATE()";
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
                return "AND DATE(ph.order_date) BETWEEN '" . $from_date . "' AND '" . $to_date . "'";
            }
            return "";
        default:
            return "";
    }
}

// Build executive filter condition - using executive_id
function getExecutiveFilterCondition($executive_filter) {
    if ($executive_filter == 0) {
        return "";
    } else {
        return "AND cc.executive_id = " . (int)$executive_filter;
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
function getExecutiveName($db, $executive_id) {
    if ($executive_id == 0) {
        return 'All Executives';
    }
    $sql = "SELECT full_name FROM USERS WHERE user_id = :id";
    $result = $db->fetchDBQuery($sql, ['id' => $executive_id], true);
    return $result ? $result['full_name'] : 'All Executives';
}

// Get coupon name
function getCouponName($db, $coupon_id) {
    if ($coupon_id == 'all') {
        return 'All Coupons';
    }
    $sql = "SELECT coupon_code FROM TX_COUPON_CODES WHERE coupon_id = :id";
    $result = $db->fetchDBQuery($sql, ['id' => $coupon_id], true);
    return $result ? $result['coupon_code'] : 'All Coupons';
}

// Function to get dashboard stats
function getDashboardStats($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $executive_condition = getExecutiveFilterCondition($executive_filter);
    $coupon_condition = getCouponFilterCondition($coupon_filter);

    $sql = "
        SELECT
            COALESCE(ROUND(SUM(ph.order_amount), 2), 0) AS total_sales
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc
            ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
    ";
    $sales = $db->fetchDBQuery($sql, [], true);

    $sql = "
        SELECT
            COUNT(DISTINCT ph.order_id) AS total_orders
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc
            ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
    ";
    $orders = $db->fetchDBQuery($sql, [], true);

    $sql = "
        SELECT
            COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) AS total_commission
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc
            ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
    ";
    $commission = $db->fetchDBQuery($sql, [], true);

    $sql = "
        SELECT
            COUNT(*) AS total_executives
        FROM USERS
        WHERE user_type = 'SE'
        AND is_deleted = 0
    ";
    $executives = $db->fetchDBQuery($sql, [], true);

    $sql = "
        SELECT
            COUNT(DISTINCT ph.created_by) AS active_executives
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc
            ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
    ";
    $active = $db->fetchDBQuery($sql, [], true);

    return [
        'total_sales' => (float)($sales['total_sales'] ?? 0),
        'total_orders' => (int)($orders['total_orders'] ?? 0),
        'total_commission' => (float)($commission['total_commission'] ?? 0),
        'total_executives' => (int)($executives['total_executives'] ?? 0),
        'active_executives' => (int)($active['active_executives'] ?? 0)
    ];
}

// Function to get sales overview data
function getSalesOverview($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $executive_condition = getExecutiveFilterCondition($executive_filter);
    $coupon_condition = getCouponFilterCondition($coupon_filter);
    
    $sql = "
        SELECT 
            DATE_FORMAT(ph.order_date, '%d %b') as date_label,
            COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as sales,
            COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as commission
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
        GROUP BY DATE(ph.order_date)
        ORDER BY ph.order_date ASC
        LIMIT 30
    ";
    return $db->fetchDBQuery($sql);
}

// Function to get sales by discount type
function getSalesByDiscountType($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $executive_condition = getExecutiveFilterCondition($executive_filter);
    $coupon_condition = getCouponFilterCondition($coupon_filter);
    
    $sql = "
        SELECT 
            cc.discount_type,
            COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
            COUNT(DISTINCT ph.order_id) as order_count
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
        GROUP BY cc.discount_type
        ORDER BY total_sales DESC
    ";
    $results = $db->fetchDBQuery($sql);
    
    $total_sales = array_sum(array_column($results, 'total_sales'));
    
    foreach ($results as &$row) {
        $row['percentage'] = $total_sales > 0 ? round(($row['total_sales'] / $total_sales) * 100, 0) : 0;
        $row['label'] = $row['discount_type'] == 'percentage' ? 'Percentage (%)' : 'Flat (₹)';
    }
    
    return $results;
}

// Function to get sales by subscription type
function getSalesBySubscriptionType($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $executive_condition = getExecutiveFilterCondition($executive_filter);
    $coupon_condition = getCouponFilterCondition($coupon_filter);
    
    $sql = "
        SELECT 
            ph.subscription_type,
            COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
            COUNT(DISTINCT ph.order_id) as order_count
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
        GROUP BY ph.subscription_type
        ORDER BY total_sales DESC
    ";
    $results = $db->fetchDBQuery($sql);
    
    $total_sales = array_sum(array_column($results, 'total_sales'));
    
    foreach ($results as &$row) {
        $row['percentage'] = $total_sales > 0 ? round(($row['total_sales'] / $total_sales) * 100, 0) : 0;
        $row['label'] = $row['subscription_type'] == 'B' ? 'Basic (B)' : ($row['subscription_type'] == 'P' ? 'Pro (P)' : 'Demo (D)');
    }
    
    return $results;
}

// Function to get executives (TOP 5 only)
function getTopExecutives($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $coupon_condition = getCouponFilterCondition($coupon_filter);
    $executive_condition = getExecutiveFilterCondition($executive_filter);
    
    $sql = "
        SELECT 
            u.user_id,
            u.full_name as executive_name,
            COUNT(DISTINCT ph.order_id) as total_orders,
            COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
            COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as total_commission,
            COUNT(DISTINCT cc.coupon_id) as active_coupons
        FROM USERS u
        LEFT JOIN TX_PURCHASE_HISTORY ph ON u.user_id = ph.created_by 
            AND ph.payment_status = 'S' 
            AND ph.amount > 0
            $date_condition
            $coupon_condition
        LEFT JOIN TX_COUPON_CODES cc ON u.user_id = cc.executive_id AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
        WHERE u.user_type = 'SE'
        AND u.is_deleted = 0
        $executive_condition
        GROUP BY u.user_id
        HAVING total_sales > 0 OR active_coupons > 0
        ORDER BY total_sales DESC
    ";
    
    if ($limit > 0) {
        $sql .= " LIMIT " . $limit;
    }
    
    return $db->fetchDBQuery($sql);
}

// Function to get recent orders
function getRecentOrders($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
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
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN USERS u ON ph.student_id = u.user_id
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        LEFT JOIN USERS ex ON cc.executive_id = ex.user_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.coupon_code LIKE 'S%'
        $date_condition
        $coupon_condition
        $executive_condition
        ORDER BY ph.order_date DESC
        LIMIT 5
    ";
    
    return $db->fetchDBQuery($sql);
}

// Function to get top performing coupons
function getTopCoupons($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $executive_condition = getExecutiveFilterCondition($executive_filter);
    $coupon_condition = getCouponFilterCondition($coupon_filter);
    
    $sql = "
        SELECT 
            cc.coupon_code,
            COUNT(ph.order_id) as total_used,
            COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as total_sales,
            COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as total_commission
        FROM TX_COUPON_CODES cc
        LEFT JOIN TX_PURCHASE_HISTORY ph ON cc.coupon_id = ph.coupon_id 
            AND ph.payment_status = 'S' 
            AND ph.amount > 0
        $date_condition
        $coupon_condition
        $executive_condition
        WHERE cc.is_active = 1
        AND cc.coupon_code LIKE 'S%'
        GROUP BY cc.coupon_id
        ORDER BY total_sales DESC
        LIMIT 5
    ";
    
    return $db->fetchDBQuery($sql);
}
try {
    // ===== GET ALL DATA =====
    $stats = getDashboardStats($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
    $sales_overview = getSalesOverview($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
    $subscription_sales = getSalesBySubscriptionType($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
    $discount_sales = getSalesByDiscountType($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date);
    $top_executives = getTopExecutives($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);
    $recent_orders = getRecentOrders($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);
    $top_coupons = getTopCoupons($db, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);

    // Get names
    $executive_name = getExecutiveName($db, $executive_filter);
    $coupon_name = getCouponName($db, $coupon_filter);

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

    // Clear any output before sending JSON
    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    // Clear any output and send error as JSON
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>