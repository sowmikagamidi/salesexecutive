<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

include_once 'config.php';
ob_clean();

header('Content-Type: application/json');

// Get parameters
$executive_id = isset($_GET['executive']) ? (int)$_GET['executive'] : 19;
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// =============================================
// FILTER CONDITION FUNCTIONS
// =============================================

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

// Build coupon filter conditions
function getCouponCondition($coupon_filter) {
    if ($coupon_filter == 'all') {
        return "";
    } else {
        return "AND ph.coupon_id = " . (int)$coupon_filter;
    }
}

// =============================================
// DATA FETCHING FUNCTIONS - USING executive_id
// =============================================

function getExecutiveStats($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $coupon_condition = getCouponCondition($coupon_filter);
    
    $sql = "
        SELECT 
            COUNT(DISTINCT ph.order_id) AS total_orders,
            COALESCE(SUM(ph.order_amount), 0) AS total_sales,
            COALESCE(SUM(ph.order_amount * 0.25), 0) AS total_commission,
            COUNT(DISTINCT ph.student_id) AS total_students
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.executive_id = :executive_id
        AND cc.is_active = 1
        $date_condition
        $coupon_condition
    ";
    
    $result = $db->fetchDBQuery($sql, ['executive_id' => $executive_id], true);
    
    $sql2 = "
        SELECT COUNT(*) AS active_coupons
        FROM TX_COUPON_CODES
        WHERE executive_id = :executive_id 
        AND is_active = 1
    ";
    $coupons = $db->fetchDBQuery($sql2, ['executive_id' => $executive_id], true);
    
    return [
        'total_orders' => $result['total_orders'] ?? 0,
        'total_sales' => (float)($result['total_sales'] ?? 0),
        'total_commission' => (float)($result['total_commission'] ?? 0),
        'total_students' => $result['total_students'] ?? 0,
        'active_coupons' => $coupons['active_coupons'] ?? 0
    ];
}

function getCouponPerformance($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit = 5, $offset = 0) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $coupon_condition = getCouponCondition($coupon_filter);
    
    $sql = "
        SELECT
            cc.coupon_id,
            cc.coupon_code,
            DATE_FORMAT(cc.created_dtm,'%d %b %Y') AS issued_on,
            DATE_FORMAT(cc.expires_at,'%d %b %Y') AS expiry_date,
            COUNT(ph.order_id) AS total_used,
            COUNT(DISTINCT ph.student_id) AS total_students,
            COALESCE(SUM(ph.order_amount),0) AS sales_amount,
            COALESCE(SUM(ph.order_amount*0.25),0) AS commission
        FROM TX_COUPON_CODES cc
        LEFT JOIN TX_PURCHASE_HISTORY ph
            ON ph.coupon_id = cc.coupon_id
            AND ph.payment_status = 'S'
            AND ph.amount > 0
            AND ph.created_by = (SELECT user_id FROM USERS WHERE user_id = cc.executive_id)
            $date_condition
        WHERE cc.executive_id = :executive_id
        AND cc.is_active = 1
        $coupon_condition
        GROUP BY
            cc.coupon_id,
            cc.coupon_code,
            cc.created_dtm,
            cc.expires_at
        ORDER BY total_used DESC
    ";
    
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    }
    
    $coupons = $db->fetchDBQuery($sql, ['executive_id' => $executive_id]);
    
    foreach ($coupons as &$c) {
        $c['sales_amount'] = (float)$c['sales_amount'];
        $c['commission'] = (float)$c['commission'];
    }
    
    return $coupons;
}

function getOrders($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit = 5, $offset = 0) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $coupon_condition = getCouponCondition($coupon_filter);
    
    $sql = "
        SELECT 
            ph.order_id,
            u.full_name AS student_name,
            cc.coupon_code,
            ph.order_amount AS amount,
            DATE_FORMAT(ph.order_date, '%d %b %Y') AS order_date_formatted,
            ph.order_date,
            ph.payment_status,
            ex.full_name AS executive_name
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN USERS u ON ph.student_id = u.user_id
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        LEFT JOIN USERS ex ON cc.executive_id = ex.user_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.executive_id = :executive_id
        AND cc.is_active = 1
        $date_condition
        $coupon_condition
        ORDER BY ph.order_date DESC
    ";
    
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    }
    
    $orders = $db->fetchDBQuery($sql, ['executive_id' => $executive_id]);
    
    foreach ($orders as &$order) {
        $order['amount'] = (float)$order['amount'];
    }
    
    return $orders;
}

function getSalesOverview($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    $coupon_condition = getCouponCondition($coupon_filter);
    
    $sql = "
        SELECT 
            DATE_FORMAT(ph.order_date, '%b %d') AS date_label,
            COALESCE(SUM(ph.order_amount), 0) AS sales,
            COALESCE(SUM(ph.order_amount * 0.25), 0) AS commission
        FROM TX_PURCHASE_HISTORY ph
        LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
        WHERE ph.payment_status = 'S'
        AND ph.amount > 0
        AND cc.executive_id = :executive_id
        AND cc.is_active = 1
        $date_condition
        $coupon_condition
        GROUP BY DATE(ph.order_date)
        ORDER BY ph.order_date ASC
    ";
    
    $result = $db->fetchDBQuery($sql, ['executive_id' => $executive_id]);
    
    foreach ($result as &$row) {
        $row['sales'] = (float)$row['sales'];
        $row['commission'] = (float)$row['commission'];
    }
    
    return $result;
}

function getExecutiveCoupons($db, $executive_id) {
    $sql = "
        SELECT 
            coupon_id,
            coupon_code
        FROM TX_COUPON_CODES
        WHERE executive_id = :executive_id
        AND is_active = 1
        ORDER BY coupon_code
    ";
    return $db->fetchDBQuery($sql, ['executive_id' => $executive_id]);
}

try {
    // Determine what data to return based on type parameter
    if ($type == 'orders') {
        // For view_all_executive orders view with pagination
        $orders = getOrders($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit, $offset);
        
        // Get total count
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $coupon_condition = getCouponCondition($coupon_filter);
        $count_sql = "
            SELECT COUNT(*) as total
            FROM TX_PURCHASE_HISTORY ph
            LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND cc.executive_id = :executive_id
            AND cc.is_active = 1
            $date_condition
            $coupon_condition
        ";
        $count_result = $db->fetchDBQuery($count_sql, ['executive_id' => $executive_id], true);
        $total_rows = $count_result['total'] ?? 0;
        
        $response = [
            'success' => true,
            'orders' => $orders,
            'total_rows' => $total_rows,
            'date_display' => getDateRangeDisplay($date_filter, $from_date, $to_date),
            'coupon_display' => getCouponDisplay($db, $executive_id, $coupon_filter),
            'coupon_id' => $coupon_filter,
            'executive_id' => $executive_id
        ];
    } elseif ($type == 'coupons') {
        // For view_all_executive coupons view with pagination
        $coupons = getCouponPerformance($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit, $offset);
        
        // Get total count
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $coupon_condition = getCouponCondition($coupon_filter);
        $count_sql = "
            SELECT COUNT(DISTINCT cc.coupon_id) as total
            FROM TX_COUPON_CODES cc
            LEFT JOIN TX_PURCHASE_HISTORY ph
                ON ph.coupon_id = cc.coupon_id
                AND ph.payment_status = 'S'
                AND ph.amount > 0
                AND ph.created_by = (SELECT user_id FROM USERS WHERE user_id = cc.executive_id)
                $date_condition
            WHERE cc.executive_id = :executive_id
            AND cc.is_active = 1
            $coupon_condition
        ";
        $count_result = $db->fetchDBQuery($count_sql, ['executive_id' => $executive_id], true);
        $total_rows = $count_result['total'] ?? 0;
        
        $response = [
            'success' => true,
            'coupons' => $coupons,
            'total_rows' => $total_rows,
            'date_display' => getDateRangeDisplay($date_filter, $from_date, $to_date),
            'coupon_display' => getCouponDisplay($db, $executive_id, $coupon_filter),
            'coupon_id' => $coupon_filter,
            'executive_id' => $executive_id
        ];
    } else {
        // Default - for dashboard (with limits)
        $stats = getExecutiveStats($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
        $coupons = getCouponPerformance($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, 5, 0);
        $orders = getOrders($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, 5, 0);
        $sales_overview = getSalesOverview($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
        $executive_coupons = getExecutiveCoupons($db, $executive_id);

        $response = [
            'success' => true,
            'stats' => $stats,
            'coupons' => $coupons,
            'orders' => $orders,
            'chart_data' => $sales_overview,
            'date_display' => getDateRangeDisplay($date_filter, $from_date, $to_date),
            'coupon_display' => getCouponDisplay($db, $executive_id, $coupon_filter),
            'coupon_id' => $coupon_filter,
            'executive_id' => $executive_id
        ];
    }

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

// Helper function to get coupon display name
function getCouponDisplay($db, $executive_id, $coupon_filter) {
    if ($coupon_filter == 'all') {
        return 'All Coupons';
    }
    $coupons = getExecutiveCoupons($db, $executive_id);
    foreach ($coupons as $c) {
        if ($c['coupon_id'] == $coupon_filter) {
            return $c['coupon_code'];
        }
    }
    return 'All Coupons';
}
?>