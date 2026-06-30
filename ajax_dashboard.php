<?php
// ajax_dashboard.php
include_once 'config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Get parameters
$executive_id = isset($_GET['executive']) ? (int)$_GET['executive'] : 19;
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

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

// Build coupon filter conditions
function getCouponCondition($coupon_filter) {
    if ($coupon_filter == 'all') {
        return "";
    } else {
        return "AND ph.coupon_id = " . (int)$coupon_filter;
    }
}

// Function to format currency with 2 decimal places
function formatCurrency($amount) {
    return number_format((float)$amount, 2);
}

// Function to get executive stats with filters
function getExecutiveStats($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $coupon_condition = getCouponCondition($coupon_filter);
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT ph.order_id) as total_orders,
                COALESCE(SUM(ph.order_amount), 0) as total_sales,
                COALESCE(SUM(ph.order_amount * 0.25), 0) as total_commission,
                COUNT(DISTINCT ph.student_id) as total_students
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND ph.created_by = :executive_id
            AND cc.is_active = 1
            $date_condition
            $coupon_condition
        ");
        $stmt->execute([':executive_id' => $executive_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get active coupons count
        $stmt2 = $pdo->prepare("
            SELECT COUNT(*) as active_coupons
            FROM tx_coupon_codes
            WHERE created_by = :executive_id 
            AND is_active = 1
        ");
        $stmt2->execute([':executive_id' => $executive_id]);
        $coupons = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_orders' => $result['total_orders'] ?? 0,
            'total_sales' => (float)$result['total_sales'] ?? 0,
            'total_commission' => (float)$result['total_commission'] ?? 0,
            'total_students' => $result['total_students'] ?? 0,
            'active_coupons' => $coupons['active_coupons'] ?? 0
        ];
    } catch(PDOException $e) {
        return [
            'total_orders' => 0,
            'total_sales' => 0,
            'total_commission' => 0,
            'total_students' => 0,
            'active_coupons' => 0
        ];
    }
}

// Function to get coupon performance with filters
function getCouponPerformance($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        
        if ($coupon_filter == 'all') {
            $coupon_condition = "";
        } else {
            $coupon_condition = "AND ph.coupon_id = " . (int)$coupon_filter;
        }
        
        $sql = "
            SELECT 
                cc.coupon_code,
                cc.coupon_id,
                DATE_FORMAT(cc.created_dtm, '%d %b %Y') as issued_on,
                DATE_FORMAT(cc.expires_at, '%d %b %Y') as expiry_date,
                COUNT(ph.order_id) as total_used,
                COUNT(DISTINCT ph.student_id) as total_students,
                COALESCE(SUM(ph.order_amount), 0) as sales_amount,
                COALESCE(SUM(ph.order_amount * 0.25), 0) as commission,
                CASE 
                    WHEN COUNT(ph.order_id) > 0 
                    THEN ROUND((COUNT(ph.order_id) / 
                        NULLIF((SELECT COUNT(*) FROM tx_purchase_history 
                         WHERE payment_status = 'S' AND amount > 0 AND created_by = :executive_id2 $date_condition), 0)) * 100, 0)
                    ELSE 0 
                END as usage_rate
            FROM tx_coupon_codes cc
            LEFT JOIN tx_purchase_history ph ON cc.coupon_id = ph.coupon_id 
                AND ph.payment_status = 'S' 
                AND ph.amount > 0
                AND ph.created_by = :executive_id
                $date_condition
            WHERE cc.is_active = 1
            AND cc.created_by = :executive_id
            GROUP BY cc.coupon_id
            ORDER BY total_used DESC
        ";
        
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':executive_id' => $executive_id,
            ':executive_id2' => $executive_id
        ]);
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_orders = 0;
        foreach ($coupons as $c) {
            $total_orders += $c['total_used'];
        }
        
        foreach ($coupons as &$c) {
            $c['usage_rate'] = $total_orders > 0 ? round(($c['total_used'] / $total_orders) * 100, 0) : 0;
            $c['sales_amount'] = (float)$c['sales_amount'];
            $c['commission'] = (float)$c['commission'];
        }
        
        return $coupons;
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get orders with filters - only 7 records
function getOrders($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit = 7) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $coupon_condition = getCouponCondition($coupon_filter);
        
        $sql = "
            SELECT 
                ph.order_id,
                u.full_name as student_name,
                cc.coupon_code,
                ph.order_amount as amount,
                DATE_FORMAT(ph.order_date, '%d %b %Y') as order_date_formatted,
                ph.order_date,
                ph.payment_status,
                ex.full_name as executive_name
            FROM tx_purchase_history ph
            LEFT JOIN users u ON ph.student_id = u.user_id
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            LEFT JOIN users ex ON ph.created_by = ex.user_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND ph.created_by = :executive_id
            AND cc.is_active = 1
            $date_condition
            $coupon_condition
            ORDER BY ph.order_date DESC
        ";
        
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':executive_id' => $executive_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orders as &$order) {
            $order['amount'] = (float)$order['amount'];
        }
        
        return $orders;
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get sales overview with filters
function getSalesOverview($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date) {
    try {
        $date_condition = getDateCondition($date_filter, $from_date, $to_date);
        $coupon_condition = getCouponCondition($coupon_filter);
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(ph.order_date, '%b %d') as date_label,
                COALESCE(SUM(ph.order_amount), 0) as sales,
                COALESCE(SUM(ph.order_amount * 0.25), 0) as commission
            FROM tx_purchase_history ph
            LEFT JOIN tx_coupon_codes cc ON ph.coupon_id = cc.coupon_id
            WHERE ph.payment_status = 'S'
            AND ph.amount > 0
            AND ph.created_by = :executive_id
            AND cc.is_active = 1
            $date_condition
            $coupon_condition
            GROUP BY DATE(ph.order_date)
            ORDER BY ph.order_date ASC
        ");
        $stmt->execute([':executive_id' => $executive_id]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($result as &$row) {
            $row['sales'] = (float)$row['sales'];
            $row['commission'] = (float)$row['commission'];
        }
        
        return $result;
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get coupons for dropdown
function getExecutiveCoupons($pdo, $executive_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                coupon_id,
                coupon_code
            FROM tx_coupon_codes
            WHERE created_by = :executive_id
            AND is_active = 1
            ORDER BY coupon_code
        ");
        $stmt->execute([':executive_id' => $executive_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// ===== GET ALL DATA =====
$stats = getExecutiveStats($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
$coupons = getCouponPerformance($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, 5);
$orders = getOrders($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, 7);
$sales_overview = getSalesOverview($pdo, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
$executive_coupons = getExecutiveCoupons($pdo, $executive_id);

// Get coupon name for display
$coupon_display = 'All Coupons';
if ($coupon_filter != 'all') {
    foreach ($executive_coupons as $c) {
        if ($c['coupon_id'] == $coupon_filter) {
            $coupon_display = $c['coupon_code'];
            break;
        }
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

// Return JSON response
$response = [
    'success' => true,
    'stats' => $stats,
    'coupons' => $coupons,
    'orders' => $orders,
    'chart_data' => $sales_overview,
    'date_display' => getDateRangeDisplay($date_filter, $from_date, $to_date),
    'coupon_display' => $coupon_display,
    'coupon_id' => $coupon_filter,
    'executive_id' => $executive_id
];

echo json_encode($response);
?>