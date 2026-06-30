<?php
// admin_dashboard.php
include_once 'config.php';

// Get filter parameters
$executive_filter = isset($_GET['executive_filter']) ? (int)$_GET['executive_filter'] : 0;
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Get all executives for dropdown
function getAllExecutivesList($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT user_id, full_name as executive_name
            FROM users
            WHERE user_type = 'SE'
            AND is_deleted = 0
            AND user_status = 'A'
            ORDER BY full_name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Get all coupons for dropdown
function getAllCouponsList($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT coupon_id, coupon_code
            FROM tx_coupon_codes
            WHERE is_active = 1
            AND coupon_code LIKE 'S%'
            ORDER BY coupon_code
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

$executives_list = getAllExecutivesList($pdo);
$coupons_list = getAllCouponsList($pdo);

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

// Function to format currency with 2 decimal places
function formatCurrency($amount) {
    return '₹ ' . number_format((float)$amount, 2);
}

// Function to format number with 2 decimal places
function formatNumber($number) {
    return number_format((float)$number, 2);
}

// Function to get executive name
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

// Function to get coupon name
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

$selected_executive_name = getExecutiveName($pdo, $executive_filter);
$selected_coupon_name = getCouponName($pdo, $coupon_filter);

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

// Function to get sales by discount type
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

// Function to get executives (TOP 5 only)
function getTopExecutives($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    try {
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
            FROM users u
            LEFT JOIN tx_purchase_history ph ON u.user_id = ph.created_by 
                AND ph.payment_status = 'S' 
                AND ph.amount > 0
                $date_condition
                $coupon_condition
            LEFT JOIN tx_coupon_codes cc ON u.user_id = cc.created_by AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
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
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// Function to get ALL executives (for view all)
function getAllExecutivesData($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date) {
    return getTopExecutives($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 0);
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
$top_executives = getTopExecutives($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);
$recent_orders = getRecentOrders($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);
$top_coupons = getTopCoupons($pdo, $date_filter, $executive_filter, $coupon_filter, $from_date, $to_date, 5);

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

$show_custom_dates = ($date_filter == 'custom');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .dashboard-container { max-width: 1400px; margin: 0 auto; }
        
        .dashboard-header {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .dashboard-header h1 {
            font-size: 24px;
            color: #057ab5;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-header h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            padding: 10px;
            border-radius: 12px;
            color: white;
            font-size: 14px;
        }
        .dashboard-header .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .dashboard-header .header-right .badge-info {
            background: #667eea;
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        /* =============================================
   FILTER SECTION - CLEAN COMPACT BAR
   ============================================= */
.filter-section {
    background: white;
    padding: 10px 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    flex-wrap: nowrap;
    align-items: flex-end;
    gap: 8px;
    overflow: visible;
    min-height: 55px;
    position: relative;
    z-index: 1;
}
.filter-section .filter-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex-shrink: 0;
    position: relative;
}
.filter-section .filter-label {
    font-size: 8px;
    font-weight: 700;
    color: #6c7a8a;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 1px;
}
.filter-section select, 
.filter-section input {
    padding: 5px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 11px;
    background: white;
    color: #2d3748;
    min-width: 90px;
    cursor: pointer;
    height: 28px;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.filter-section select:hover, 
.filter-section input:hover {
    border-color: #b0c4de;
}
.filter-section select:focus, 
.filter-section input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.12);
}
.filter-section .filter-divider {
    width: 1px;
    height: 28px;
    background: #e2e8f0;
    flex-shrink: 0;
    align-self: flex-end;
}
.filter-section .filter-info {
    font-size: 10px;
    color: #718096;
    background: #f7fafc;
    padding: 4px 12px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    height: 28px;
    white-space: nowrap;
    flex-shrink: 0;
    border: 1px solid #edf2f7;
}
.filter-section .filter-info strong {
    color: #2d3748;
    font-weight: 600;
}

/* Custom Date Group */
.filter-section .custom-date-group {
    display: none;
    align-items: flex-end;
    gap: 6px;
    flex-shrink: 0;
}
.filter-section .custom-date-group.show {
    display: flex;
}
.filter-section .custom-date-group input {
    min-width: 80px;
    padding: 5px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 10px;
    height: 28px;
    cursor: pointer;
}
.filter-section .custom-date-group .filter-group {
    flex-direction: column;
}
.filter-section .custom-date-group .filter-group .filter-label {
    font-size: 7px;
}

/* Loading Spinner */
.filter-loading {
    display: none;
    align-items: center;
    font-size: 10px;
    color: #667eea;
    font-weight: 500;
    height: 28px;
    flex-shrink: 0;
}
.filter-loading .spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid #e2e8f0;
    border-top: 2px solid #667eea;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-right: 6px;
    vertical-align: middle;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Buttons */
.btn-apply {
    padding: 5px 18px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    height: 28px;
    min-width: 55px;
    flex-shrink: 0;
    letter-spacing: 0.3px;
}
.btn-apply:hover {
    background: #5a6fd6;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}
.btn-apply:active {
    transform: translateY(0);
}

.btn-reset-filter {
    padding: 5px 14px;
    background: #fc8181;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    height: 28px;
    flex-shrink: 0;
}
.btn-reset-filter:hover {
    background: #f56565;
}

/* =============================================
   SEARCHABLE DROPDOWN - CLEAN OUTSIDE BAR
   ============================================= */
.searchable-dropdown {
    position: relative;
    min-width: 120px;
    flex-shrink: 0;
}
.searchable-dropdown .dropdown-input {
    padding: 5px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 11px;
    background: white;
    color: #2d3748;
    width: 100%;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 28px;
    min-width: 120px;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.searchable-dropdown .dropdown-input:hover {
    border-color: #b0c4de;
}
.searchable-dropdown .dropdown-input:focus-within {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.12);
}
.searchable-dropdown .dropdown-input .arrow {
    transition: transform 0.25s ease;
    font-size: 8px;
    color: #a0aec0;
    margin-left: 6px;
    flex-shrink: 0;
}
.searchable-dropdown .dropdown-input .arrow.open {
    transform: rotate(180deg);
}

/* Dropdown Menu - Opens OUTSIDE the bar */
.searchable-dropdown .dropdown-menu {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: auto;
    min-width: 220px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-top: 0;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    z-index: 9999;
    max-height: 280px;
    overflow: hidden;
    padding: 4px 0;
}
.searchable-dropdown .dropdown-menu.open {
    display: block;
}
.searchable-dropdown .dropdown-menu .search-box {
    padding: 6px 10px;
    border-bottom: 1px solid #edf2f7;
    position: sticky;
    top: 0;
    background: white;
    z-index: 2;
}
.searchable-dropdown .dropdown-menu .search-box input {
    width: 100%;
    padding: 5px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 11px;
    outline: none;
    height: 28px;
    background: #f7fafc;
    transition: border-color 0.2s;
}
.searchable-dropdown .dropdown-menu .search-box input:focus {
    border-color: #667eea;
    background: white;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}
.searchable-dropdown .dropdown-menu .search-box input::placeholder {
    color: #a0aec0;
    font-size: 10px;
}

.searchable-dropdown .dropdown-menu .options {
    max-height: 200px;
    overflow-y: auto;
    padding: 4px 0;
}
.searchable-dropdown .dropdown-menu .options .option-item {
    padding: 6px 14px;
    cursor: pointer;
    font-size: 11px;
    color: #2d3748;
    transition: background 0.15s;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.searchable-dropdown .dropdown-menu .options .option-item:hover {
    background: #f7fafc;
}
.searchable-dropdown .dropdown-menu .options .option-item.selected {
    background: #ebf4ff;
    color: #667eea;
    font-weight: 600;
}
.searchable-dropdown .dropdown-menu .options .option-item .check {
    color: #667eea;
    font-size: 11px;
    font-weight: 700;
}

/* Scrollbar styling for dropdown */
.searchable-dropdown .dropdown-menu .options::-webkit-scrollbar {
    width: 4px;
}
.searchable-dropdown .dropdown-menu .options::-webkit-scrollbar-track {
    background: #f7fafc;
}
.searchable-dropdown .dropdown-menu .options::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 4px;
}
.searchable-dropdown .dropdown-menu .options::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

/* =============================================
   CHIPS - BELOW DROPDOWN
   ============================================= */
.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin-top: 3px;
    min-height: 18px;
}
.filter-chips .chip {
    display: inline-flex;
    align-items: center;
    background: #ebf4ff;
    color: #667eea;
    padding: 1px 6px 1px 8px;
    border-radius: 12px;
    font-size: 9px;
    font-weight: 500;
    gap: 3px;
    border: 1px solid rgba(102, 126, 234, 0.15);
}
.filter-chips .chip .remove {
    cursor: pointer;
    font-size: 10px;
    color: #667eea;
    opacity: 0.6;
    transition: opacity 0.2s;
    line-height: 1;
}
.filter-chips .chip .remove:hover {
    opacity: 1;
}

/* =============================================
   RESPONSIVE DESIGN
   ============================================= */
@media (max-width: 1200px) {
    .filter-section {
        flex-wrap: wrap;
        gap: 6px;
        padding: 10px 15px;
    }
    .filter-section select, 
    .filter-section input {
        min-width: 70px;
        font-size: 10px;
    }
    .searchable-dropdown {
        min-width: 100px;
    }
    .searchable-dropdown .dropdown-input {
        min-width: 100px;
    }
    .searchable-dropdown .dropdown-menu {
        min-width: 180px;
    }
}
@media (max-width: 992px) {
    .filter-section .filter-divider { display: none; }
}
@media (max-width: 768px) {
    .filter-section {
        flex-direction: column;
        align-items: stretch;
        padding: 12px 15px;
        gap: 6px;
        overflow: visible;
    }
    .filter-section .filter-group { 
        width: 100%; 
        flex-shrink: 1;
    }
    .filter-section .filter-divider { display: none; }
    .filter-section .filter-info { 
        text-align: center; 
        white-space: normal; 
        height: auto; 
        padding: 6px 12px;
        justify-content: center;
    }
    .filter-section .custom-date-group { 
        flex-wrap: wrap; 
        width: 100%;
    }
    .filter-section .custom-date-group .filter-group { 
        flex: 1; 
        min-width: 80px;
    }
    .searchable-dropdown { 
        min-width: 100%; 
        width: 100%;
    }
    .searchable-dropdown .dropdown-input { 
        min-width: 100%; 
        width: 100%;
    }
    .searchable-dropdown .dropdown-menu {
        min-width: 100%;
        width: 100%;
        left: 0;
        right: 0;
    }
    .btn-apply { width: 100%; }
    .btn-reset-filter { width: 100%; text-align: center; }
    .dashboard-header { flex-direction: column; align-items: stretch; }
    .filter-section select, 
    .filter-section input {
        min-width: 100%;
        width: 100%;
    }
}
@media (max-width: 480px) {
    .filter-section {
        padding: 10px 12px;
    }
    .filter-section .custom-date-group {
        flex-direction: column;
    }
    .filter-section .custom-date-group .filter-group {
        min-width: 100%;
    }
}
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #667eea;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin: 4px 0;
        }
        .stat-card .stat-sub {
            font-size: 11px;
            color: #95a5a6;
        }
        .stat-card.blue { border-left-color: #3498db; }
        .stat-card.blue .stat-value { color: #3498db; }
        .stat-card.green { border-left-color: #27ae60; }
        .stat-card.green .stat-value { color: #27ae60; }
        .stat-card.purple { border-left-color: #9b59b6; }
        .stat-card.purple .stat-value { color: #9b59b6; }
        .stat-card.orange { border-left-color: #e67e22; }
        .stat-card.orange .stat-value { color: #e67e22; }
        
        .section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
        }
        .section-header .section-title {
            border: none;
            margin: 0;
            padding: 0;
        }
        
        .chart-row {
            display: grid;
            grid-template-columns: 1.0fr 1fr;
            gap: 20px;
        }
        .chart-box { 
            background: #fafbfc; 
            padding: 12px 15px; 
            border-radius: 8px;
            border: 1px solid #ecf0f1;
        }
        .chart-box canvas { 
            max-height: 180px; 
            width: 100% !important; 
        }
        .chart-box .chart-title {
            font-size: 12px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
        }
        
        .two-col-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse;
            table-layout: fixed;
        }
        table th {
            background: #f8f9fa;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #ecf0f1;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 12px;
        }
        table tr:hover { background: #f8f9fa; }
        
        .coupon-table th:nth-child(1),
        .coupon-table td:nth-child(1) { width: 30%; }
        .coupon-table th:nth-child(2),
        .coupon-table td:nth-child(2) { width: 20%; }
        .coupon-table th:nth-child(3),
        .coupon-table td:nth-child(3) { width: 25%; }
        .coupon-table th:nth-child(4),
        .coupon-table td:nth-child(4) { width: 25%; }
        
        .btn {
            display: inline-block;
            padding: 5px 14px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn:hover { 
            background: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }
        .btn-secondary { background: #95a5a6; }
        .btn-secondary:hover { background: #7f8c8d; }
        .btn-sm { padding: 3px 10px; font-size: 11px; }
        .btn-primary { background: #667eea; }
        .btn-primary:hover { background: #5a6fd6; }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-badge.success { background: #d4edda; color: #155724; }
        
        .coupon-code {
            font-weight: 600;
            color: #2c3e50;
            background: #f0f2f5;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-family: 'Courier New', monospace;
        }
        
        .executive-rank {
            display: inline-block;
            width: 24px;
            height: 24px;
            line-height: 24px;
            text-align: center;
            background: #f0f2f5;
            border-radius: 50%;
            font-weight: 600;
            font-size: 12px;
            color: #2c3e50;
        }
        .executive-rank.gold { background: #ffd700; color: #856404; }
        .executive-rank.silver { background: #c0c0c0; color: #495057; }
        .executive-rank.bronze { background: #cd7f32; color: white; }
        
        .badge-s {
            display: inline-block;
            background: #667eea;
            color: white;
            font-size: 9px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            margin-left: 6px;
        }
        
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #7f8c8d;
        }
        .empty-state .icon { font-size: 36px; margin-bottom: 8px; }
        
        @media (max-width: 992px) {
            .chart-row { grid-template-columns: 1fr; }
            .two-col-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            table th, table td { padding: 5px 8px; font-size: 11px; }
            .section { padding: 15px; }
            .section-header {
                flex-direction: column;
                gap: 8px;
                align-items: stretch;
            }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <h1>
                <i class="fas fa-chart-pie"></i>
                Admin Dashboard
                <span style="font-size: 14px; font-weight: 400; color: #7f8c8d; margin-left: 5px;">Monitor overall performance</span>
            </h1>
            
        </div>

        <!-- Filter Section - All in One Line -->
        <div class="filter-section">
            <!-- Executive Filter -->
            <div class="filter-group">
                <span class="filter-label">👤 Executive</span>
                <div class="searchable-dropdown" id="executiveDropdown">
                    <div class="dropdown-input" onclick="toggleDropdown('executiveDropdown')">
                        <span id="executiveSelected"><?php echo htmlspecialchars($selected_executive_name); ?></span>
                        <span class="arrow" id="executiveArrow">▼</span>
                    </div>
                    <div class="dropdown-menu" id="executiveMenu">
                        <div class="search-box">
                            <input type="text" placeholder="Search..." onkeyup="filterOptions('executiveDropdown', this.value)">
                        </div>
                        <div class="options">
                            <div class="option-item <?php echo $executive_filter == 0 ? 'selected' : ''; ?>" data-value="0" onclick="selectOption('executiveDropdown', this, 'All Executives', '0')">
                                All Executives
                                <span class="check"><?php echo $executive_filter == 0 ? '✓' : ''; ?></span>
                            </div>
                            <?php foreach ($executives_list as $exec): ?>
                            <div class="option-item <?php echo $executive_filter == $exec['user_id'] ? 'selected' : ''; ?>" data-value="<?php echo $exec['user_id']; ?>" onclick="selectOption('executiveDropdown', this, '<?php echo htmlspecialchars($exec['executive_name']); ?>', '<?php echo $exec['user_id']; ?>')">
                                <?php echo htmlspecialchars($exec['executive_name']); ?>
                                <span class="check"><?php echo $executive_filter == $exec['user_id'] ? '✓' : ''; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="filter-chips" id="executiveChips">
                    <?php if ($executive_filter > 0): ?>
                    <span class="chip">
                        <?php echo htmlspecialchars($selected_executive_name); ?>
                        <span class="remove" onclick="clearFilter('executiveDropdown')">×</span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="filter-divider"></div>
            
            <!-- Coupon Filter -->
            <div class="filter-group">
                <span class="filter-label">🏷️ Coupon</span>
                <div class="searchable-dropdown" id="couponDropdown">
                    <div class="dropdown-input" onclick="toggleDropdown('couponDropdown')">
                        <span id="couponSelected"><?php echo htmlspecialchars($selected_coupon_name); ?></span>
                        <span class="arrow" id="couponArrow">▼</span>
                    </div>
                    <div class="dropdown-menu" id="couponMenu">
                        <div class="search-box">
                            <input type="text" placeholder="Search..." onkeyup="filterOptions('couponDropdown', this.value)">
                        </div>
                        <div class="options">
                            <div class="option-item <?php echo $coupon_filter == 'all' ? 'selected' : ''; ?>" data-value="all" onclick="selectOption('couponDropdown', this, 'All Coupons', 'all')">
                                All Coupons
                                <span class="check"><?php echo $coupon_filter == 'all' ? '✓' : ''; ?></span>
                            </div>
                            <?php foreach ($coupons_list as $coupon): ?>
                            <div class="option-item <?php echo $coupon_filter == $coupon['coupon_id'] ? 'selected' : ''; ?>" data-value="<?php echo $coupon['coupon_id']; ?>" onclick="selectOption('couponDropdown', this, '<?php echo htmlspecialchars($coupon['coupon_code']); ?>', '<?php echo $coupon['coupon_id']; ?>')">
                                <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                                <span class="check"><?php echo $coupon_filter == $coupon['coupon_id'] ? '✓' : ''; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="filter-chips" id="couponChips">
                    <?php if ($coupon_filter != 'all'): ?>
                    <span class="chip">
                        <?php echo htmlspecialchars($selected_coupon_name); ?>
                        <span class="remove" onclick="clearFilter('couponDropdown')">×</span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="filter-divider"></div>
            
            <!-- Date Range Filter -->
            <div class="filter-group">
                <span class="filter-label">📅 Date Range</span>
                <select id="date_filter" name="date_filter" onchange="toggleDateGroup(this)">
                    <option value="all" <?php echo $date_filter == 'all' ? 'selected' : ''; ?>>All Time</option>
                    <option value="today" <?php echo $date_filter == 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="week" <?php echo $date_filter == 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="15days" <?php echo $date_filter == '15days' ? 'selected' : ''; ?>>Last 15 Days</option>
                    <option value="month" <?php echo $date_filter == 'month' ? 'selected' : ''; ?>>Last 1 Month</option>
                    <option value="3months" <?php echo $date_filter == '3months' ? 'selected' : ''; ?>>Last 3 Months</option>
                    <option value="6months" <?php echo $date_filter == '6months' ? 'selected' : ''; ?>>Last 6 Months</option>
                    <option value="year" <?php echo $date_filter == 'year' ? 'selected' : ''; ?>>Last 1 Year</option>
                    <option value="custom" <?php echo $date_filter == 'custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
            </div>
            
            <!-- Custom Date Range -->
            <div class="custom-date-group <?php echo $show_custom_dates ? 'show' : ''; ?>">
                <div class="filter-group">
                    <span class="filter-label">From</span>
                    <input type="date" id="from_date" value="<?php echo $from_date; ?>">
                </div>
                <div class="filter-group">
                    <span class="filter-label">To</span>
                    <input type="date" id="to_date" value="<?php echo $to_date; ?>">
                </div>
            </div>
            
            <div class="filter-divider"></div>
            
            <div class="filter-info" id="filterInfo">
                <strong><?php echo getDateRangeDisplay($date_filter, $from_date, $to_date); ?></strong>
                <?php if ($executive_filter > 0): ?>
                    | Exec: <strong><?php echo htmlspecialchars($selected_executive_name); ?></strong>
                <?php endif; ?>
                <?php if ($coupon_filter != 'all'): ?>
                    | Cpn: <strong><?php echo htmlspecialchars($selected_coupon_name); ?></strong>
                <?php endif; ?>
            </div>
            
            <button class="btn-apply" id="applyBtn"><i class="fas fa-check"></i> Apply</button>
            <button class="btn-reset-filter" id="resetBtn"><i class="fas fa-undo"></i> Reset</button>
            
            
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card blue">
                <div class="stat-label">Total Sales</div>
                <div class="stat-value" id="totalSales"><?php echo formatCurrency($stats['total_sales']); ?></div>
                <div class="stat-sub">This Period</div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Total Orders</div>
                <div class="stat-value" id="totalOrders"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="stat-sub">This Period</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-label">Total Commission (25%)</div>
                <div class="stat-value" id="totalCommission"><?php echo formatCurrency($stats['total_commission']); ?></div>
                <div class="stat-sub">This Period</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label">Total Executives</div>
                <div class="stat-value" id="totalExecutives"><?php echo $stats['total_executives']; ?></div>
                <div class="stat-sub">Active: <?php echo $stats['active_executives']; ?></div>
            </div>
        </div>

       

        <!-- Top Executives -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <?php if ($executive_filter > 0): ?>
                        🏆 <?php echo htmlspecialchars($selected_executive_name); ?> - Details
                    <?php else: ?>
                        🏆 Top Executives by Sales
                    <?php endif; ?>
                </div>
                <a href="view_all.php?type=executives&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?><?php echo $executive_filter > 0 ? '&executive_filter='.$executive_filter : ''; ?>" class="btn btn-sm btn-primary" target="_blank">View All Executives →</a>
            </div>
            
            <?php if (count($top_executives) > 0): ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:8%">#</th>
                            <th style="width:27%">Executive Name</th>
                            <th style="width:15%">Total Orders</th>
                            <th style="width:20%">Sales (Order Amount)</th>
                            <th style="width:20%">Commission (25%)</th>
                            <th style="width:10%">Active Coupons</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_executives as $index => $exec): 
                            $rank = $index + 1;
                            $rank_class = $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : ($rank == 3 ? 'bronze' : ''));
                        ?>
                        <tr>
                            <td><span class="executive-rank <?php echo $rank_class; ?>"><?php echo $rank; ?></span></td>
                            <td><strong><?php echo htmlspecialchars($exec['executive_name']); ?></strong></td>
                            <td><?php echo $exec['total_orders']; ?></td>
                            <td><?php echo formatCurrency($exec['total_sales']); ?></td>
                            <td><?php echo formatCurrency($exec['total_commission']); ?></td>
                            <td><?php echo $exec['active_coupons']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="icon">🏆</div>
                <p>No executive data available</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Orders & Top Performing Coupons (Side by Side) -->
        <div class="two-col-grid">
            <!-- Recent Orders -->
            <div class="section" style="margin-bottom: 0;">
                <div class="section-header">
                    <div class="section-title">🛒 Recent Orders</div>
                    
                </div>
                
                <?php if (count($recent_orders) > 0): ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:12%">Order ID</th>
                                <th style="width:23%">Student</th>
                                <th style="width:40%">Executive (Coupon)</th>
                                <th style="width:25%">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td><strong>#<?php echo $order['order_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($order['student_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($order['executive_name'] ?? 'N/A'); ?> <span class="coupon-code">(<?php echo htmlspecialchars($order['coupon_code'] ?? 'N/A'); ?>)</span></td>
                                <td><?php echo formatCurrency($order['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <p>No orders found</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Top Performing Coupons -->
            <div class="section" style="margin-bottom: 0;">
                <div class="section-header">
                    <div class="section-title">🎫 Most Used Coupons</div>
                   
                </div>
                
                <?php if (count($top_coupons) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="coupon-table">
                        <thead>
                            <tr>
                                <th>Coupon Code</th>
                                <th>Used</th>
                                <th>Sales (₹)</th>
                                <th>Commission (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_coupons as $coupon): ?>
                            <tr>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($coupon['coupon_code']); ?></span></td>
                                <td><strong><?php echo $coupon['total_used']; ?></strong></td>
                                <td><?php echo formatCurrency($coupon['total_sales']); ?></td>
                                <td><?php echo formatCurrency($coupon['total_commission']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="icon">🎫</div>
                    <p>No coupon data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
         <!-- Sales & Commission Overview -->
        <div class="section">
            <div class="section-title">📈 Sales & Commission Overview</div>
            <div class="chart-row">
                <div class="chart-box">
                    <div class="chart-title">Sales & Commission Trend</div>
                    <canvas id="salesChart"></canvas>
                </div>
                <div class="chart-box">
                    <div style="font-size: 12px; font-weight: 600; color: #2c3e50; margin-bottom: 10px; text-align: center;">Sales by Discount Type</div>
                    <div style="display: flex; justify-content: center;">
                        <div style="width: 150px; height: 150px; position: relative;">
                            <canvas id="discountChart"></canvas>
                        </div>
                    </div>
                    <div style="margin-top: 8px; display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;" id="discountLegend">
                        <?php foreach ($discount_sales as $discount): 
                            $dlabel = $discount['label'];
                            $dpercentage = $discount['percentage'];
                            $dcolor = $discount['discount_type'] == 'percentage' ? '#3498db' : '#27ae60';
                        ?>
                        <div style="display: flex; align-items: center; gap: 4px; font-size: 11px;">
                            <span style="display: inline-block; width: 10px; height: 10px; background: <?php echo $dcolor; ?>; border-radius: 50%;"></span>
                            <span><?php echo $dlabel; ?> (<?php echo $dpercentage; ?>%)</span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($discount_sales)): ?>
                        <div style="color: #7f8c8d; font-size: 12px;">No data available</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // =============================================
        // SEARCHABLE DROPDOWN FUNCTIONS
        // =============================================
        function toggleDropdown(id) {
            var menu = document.getElementById(id).querySelector('.dropdown-menu');
            var arrow = document.getElementById(id).querySelector('.arrow');
            var isOpen = menu.classList.contains('open');
            
            document.querySelectorAll('.dropdown-menu').forEach(function(m) {
                m.classList.remove('open');
            });
            document.querySelectorAll('.arrow').forEach(function(a) {
                a.classList.remove('open');
            });
            
            if (!isOpen) {
                menu.classList.add('open');
                arrow.classList.add('open');
            }
        }

        function filterOptions(id, search) {
            var options = document.getElementById(id).querySelectorAll('.option-item');
            var searchLower = search.toLowerCase();
            options.forEach(function(opt) {
                var text = opt.textContent.trim().toLowerCase();
                if (text.indexOf(searchLower) > -1) {
                    opt.style.display = 'block';
                } else {
                    opt.style.display = 'none';
                }
            });
        }

        function selectOption(id, element, label, value) {
            var container = document.getElementById(id);
            var selectedSpan = container.querySelector('.dropdown-input span');
            var menu = container.querySelector('.dropdown-menu');
            var arrow = container.querySelector('.arrow');
            
            selectedSpan.textContent = label;
            
            var chipsContainer = document.getElementById(id + 'Chips');
            if (chipsContainer) {
                if (value == '0' || value == 'all') {
                    chipsContainer.innerHTML = '';
                } else {
                    chipsContainer.innerHTML = '<span class="chip">' + label + ' <span class="remove" onclick="clearFilter(\'' + id + '\')">×</span></span>';
                }
            }
            
            container.querySelectorAll('.option-item').forEach(function(opt) {
                opt.classList.remove('selected');
                var check = opt.querySelector('.check');
                if (check) check.textContent = '';
            });
            element.classList.add('selected');
            var check = element.querySelector('.check');
            if (check) check.textContent = '✓';
            
            menu.classList.remove('open');
            arrow.classList.remove('open');
        }

        function clearFilter(id) {
            var container = document.getElementById(id);
            var selectedSpan = container.querySelector('.dropdown-input span');
            var chipsContainer = document.getElementById(id + 'Chips');
            
            if (id === 'executiveDropdown') {
                selectedSpan.textContent = 'All Executives';
                if (chipsContainer) chipsContainer.innerHTML = '';
                container.querySelectorAll('.option-item').forEach(function(opt) {
                    opt.classList.remove('selected');
                    var check = opt.querySelector('.check');
                    if (check) check.textContent = '';
                    if (opt.getAttribute('data-value') == '0') {
                        opt.classList.add('selected');
                        var check2 = opt.querySelector('.check');
                        if (check2) check2.textContent = '✓';
                    }
                });
            } else if (id === 'couponDropdown') {
                selectedSpan.textContent = 'All Coupons';
                if (chipsContainer) chipsContainer.innerHTML = '';
                container.querySelectorAll('.option-item').forEach(function(opt) {
                    opt.classList.remove('selected');
                    var check = opt.querySelector('.check');
                    if (check) check.textContent = '';
                    if (opt.getAttribute('data-value') == 'all') {
                        opt.classList.add('selected');
                        var check2 = opt.querySelector('.check');
                        if (check2) check2.textContent = '✓';
                    }
                });
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.searchable-dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(function(m) {
                    m.classList.remove('open');
                });
                document.querySelectorAll('.arrow').forEach(function(a) {
                    a.classList.remove('open');
                });
            }
        });

        // Date filter toggle function
        function toggleDateGroup(select) {
            var customGroup = select.closest('.filter-section').querySelector('.custom-date-group');
            if (select.value === 'custom') {
                customGroup.classList.add('show');
            } else {
                customGroup.classList.remove('show');
            }
        }

        // =============================================
        // INITIALIZE CHARTS
        // =============================================
        
        var ctx = document.getElementById('salesChart').getContext('2d');
        var salesData = <?php echo json_encode($sales_overview); ?>;
        
        var labels = salesData.length > 0 ? salesData.map(item => item.date_label) : ['No Data'];
        var sales = salesData.length > 0 ? salesData.map(item => parseFloat(item.sales).toFixed(2)) : [0];
        var commissions = salesData.length > 0 ? salesData.map(item => parseFloat(item.commission).toFixed(2)) : [0];

        window.salesChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Sales (₹)',
                    data: sales,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 3,
                    borderWidth: 2
                }, {
                    label: 'Commission (₹)',
                    data: commissions,
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#27ae60',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 3,
                    borderWidth: 2,
                    borderDash: [5, 5]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 12,
                            font: { size: 10 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₹' + parseFloat(context.parsed.y).toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹ ' + parseFloat(value).toFixed(2);
                            },
                            font: { size: 9 }
                        },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 9 } }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });

        // Doughnut Chart
        var discountCtx = document.getElementById('discountChart').getContext('2d');
        var discountData = <?php echo json_encode($discount_sales); ?>;
        
        var discountLabels = discountData.length > 0 ? discountData.map(item => item.label) : ['No Data'];
        var discountValues = discountData.length > 0 ? discountData.map(item => item.total_sales) : [1];
        var discountColors = discountData.length > 0 ? discountData.map(item => 
            item.discount_type == 'percentage' ? '#3498db' : '#27ae60'
        ) : ['#95a5a6'];

        window.discountChartInstance = new Chart(discountCtx, {
            type: 'doughnut',
            data: {
                labels: discountLabels,
                datasets: [{
                    data: discountValues,
                    backgroundColor: discountColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.label + ': ₹' + context.parsed.toFixed(2) + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });

        // =============================================
        // APPLY FILTERS
        // =============================================
        function applyFilters() {
            $('#filterLoading').addClass('show');
            
            var execSelected = document.querySelector('#executiveDropdown .option-item.selected');
            var execValue = execSelected ? execSelected.getAttribute('data-value') : '0';
            
            var couponSelected = document.querySelector('#couponDropdown .option-item.selected');
            var couponValue = couponSelected ? couponSelected.getAttribute('data-value') : 'all';
            
            var formData = {
                executive_filter: execValue,
                coupon_filter: couponValue,
                date_filter: document.getElementById('date_filter').value,
                from_date: document.getElementById('from_date').value,
                to_date: document.getElementById('to_date').value
            };
            
            $.ajax({
                url: 'ajax_admin_dashboard.php',
                type: 'GET',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        updateDashboard(response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                },
                complete: function() {
                    $('#filterLoading').removeClass('show');
                }
            });
        }

        function updateDashboard(response) {
            // Update Stats
            $('#totalSales').text('₹ ' + formatNumber(response.stats.total_sales));
            $('#totalOrders').text(formatNumber(response.stats.total_orders));
            $('#totalCommission').text('₹ ' + formatNumber(response.stats.total_commission));
            $('#totalExecutives').text(response.stats.total_executives);
            $('.stat-card.orange .stat-sub').text('Active: ' + response.stats.active_executives);
            
            // Update Header Badges
            $('.badge-info:first').text('👤 ' + response.executive_name);
            $('.badge-info:eq(1)').text('🏷️ ' + response.coupon_name);
            
            // Update Filter Info
            var filterHtml = '<strong>' + response.date_display + '</strong>';
            if (response.executive_id > 0) {
                filterHtml += ' | Exec: <strong>' + response.executive_name + '</strong>';
            }
            if (response.coupon_id != 'all') {
                filterHtml += ' | Cpn: <strong>' + response.coupon_name + '</strong>';
            }
            $('#filterInfo').html(filterHtml);
            
            // Update Top Executives Section Title
            if (response.executive_id > 0) {
                $('.section:has(.section-title:contains("Top Executives")) .section-title').html('🏆 ' + response.executive_name + ' - Details');
            } else {
                $('.section:has(.section-title:contains("Top Executives")) .section-title').html('🏆 Top Executives by Sales');
            }
            
            // Update View All Link
            var baseUrl = 'view_all.php';
            var params = [];
            if (document.getElementById('date_filter').value != 'all') {
                params.push('date_filter=' + document.getElementById('date_filter').value);
            }
            if (response.coupon_id != 'all') {
                params.push('coupon_filter=' + response.coupon_id);
            }
            if (document.getElementById('from_date').value) {
                params.push('from_date=' + document.getElementById('from_date').value);
            }
            if (document.getElementById('to_date').value) {
                params.push('to_date=' + document.getElementById('to_date').value);
            }
            if (response.executive_id > 0) {
                params.push('executive_filter=' + response.executive_id);
            }
            var queryString = params.length > 0 ? '?' + params.join('&') : '';
            
            $('.section:has(.section-title:contains("Top Executives")) .btn-primary').attr('href', baseUrl + '?type=executives' + queryString);
            
            // Update Discount Chart
            updateDiscountChart(response.discount_sales);
            
            // Update Line Chart
            updateChart(response.chart_data);
            
            // Update Tables
            updateExecutivesTable(response.executives);
            updateOrdersTable(response.orders);
            updateCouponsTable(response.coupons);
        }

        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function updateChart(chartData) {
            var labels = chartData.length > 0 ? chartData.map(item => item.date_label) : ['No Data'];
            var sales = chartData.length > 0 ? chartData.map(item => parseFloat(item.sales).toFixed(2)) : [0];
            var commissions = chartData.length > 0 ? chartData.map(item => parseFloat(item.commission).toFixed(2)) : [0];
            
            if (window.salesChartInstance) {
                window.salesChartInstance.data.labels = labels;
                window.salesChartInstance.data.datasets[0].data = sales;
                window.salesChartInstance.data.datasets[1].data = commissions;
                window.salesChartInstance.update();
            }
        }

        function updateDiscountChart(discountData) {
            var labels = discountData.length > 0 ? discountData.map(item => item.label) : ['No Data'];
            var values = discountData.length > 0 ? discountData.map(item => item.total_sales) : [1];
            var colors = discountData.length > 0 ? discountData.map(item => 
                item.discount_type == 'percentage' ? '#3498db' : '#27ae60'
            ) : ['#95a5a6'];
            
            if (window.discountChartInstance) {
                window.discountChartInstance.data.labels = labels;
                window.discountChartInstance.data.datasets[0].data = values;
                window.discountChartInstance.data.datasets[0].backgroundColor = colors;
                window.discountChartInstance.update();
            }
            
            var legendHtml = '';
            if (discountData.length > 0) {
                $.each(discountData, function(index, item) {
                    var color = item.discount_type == 'percentage' ? '#3498db' : '#27ae60';
                    legendHtml += '<div style="display: flex; align-items: center; gap: 4px; font-size: 11px;">' +
                        '<span style="display: inline-block; width: 10px; height: 10px; background: ' + color + '; border-radius: 50%;"></span>' +
                        '<span>' + item.label + ' (' + item.percentage + '%)</span>' +
                    '</div>';
                });
            } else {
                legendHtml = '<div style="color: #7f8c8d; font-size: 12px;">No data available</div>';
            }
            document.getElementById('discountLegend').innerHTML = legendHtml;
        }

        function updateExecutivesTable(executives) {
            var tbody = $('.section:has(.section-title:contains("Top Executives")) table tbody');
            if (executives && executives.length > 0) {
                var html = '';
                $.each(executives, function(index, exec) {
                    var rank = index + 1;
                    var rankClass = rank == 1 ? 'gold' : (rank == 2 ? 'silver' : (rank == 3 ? 'bronze' : ''));
                    html += '<tr>' +
                        '<td><span class="executive-rank ' + rankClass + '">' + rank + '</span></td>' +
                        '<td><strong>' + exec.executive_name + '</strong></td>' +
                        '<td>' + exec.total_orders + '</td>' +
                        '<td>₹ ' + formatNumber(exec.total_sales) + '</td>' +
                        '<td>₹ ' + formatNumber(exec.total_commission) + '</td>' +
                        '<td>' + exec.active_coupons + '</td>' +
                    '</tr>';
                });
                tbody.html(html);
            } else {
                tbody.html('<tr><td colspan="6" style="text-align: center; padding: 20px; color: #7f8c8d;">No data available</td></tr>');
            }
        }

        function updateOrdersTable(orders) {
            var tbody = $('.section:has(.section-title:contains("Recent Orders")) table tbody');
            if (orders && orders.length > 0) {
                var html = '';
                $.each(orders, function(index, order) {
                    html += '<tr>' +
                        '<td><strong>#' + order.order_id + '</strong></td>' +
                        '<td>' + (order.student_name || 'N/A') + '</td>' +
                        '<td>' + (order.executive_name || 'N/A') + ' <span class="coupon-code">(' + (order.coupon_code || 'N/A') + ')</span></td>' +
                        '<td>₹ ' + formatNumber(order.amount) + '</td>' +
                    '</tr>';
                });
                tbody.html(html);
            } else {
                tbody.html('<tr><td colspan="4" style="text-align: center; padding: 20px; color: #7f8c8d;">No orders found</td></tr>');
            }
        }

        function updateCouponsTable(coupons) {
            var tbody = $('.section:has(.section-title:contains("Most Used Coupons")) table tbody');
            if (coupons && coupons.length > 0) {
                var html = '';
                $.each(coupons, function(index, coupon) {
                    html += '<tr>' +
                        '<td><span class="coupon-code">' + coupon.coupon_code + '</span></td>' +
                        '<td><strong>' + coupon.total_used + '</strong></td>' +
                        '<td>₹ ' + formatNumber(coupon.total_sales) + '</td>' +
                        '<td>₹ ' + formatNumber(coupon.total_commission) + '</td>' +
                    '</tr>';
                });
                tbody.html(html);
            } else {
                tbody.html('<tr><td colspan="4" style="text-align: center; padding: 20px; color: #7f8c8d;">No coupon data available</td></tr>');
            }
        }

        // =============================================
        // EVENT HANDLERS
        // =============================================
        
        document.getElementById('applyBtn').addEventListener('click', applyFilters);

        document.getElementById('resetBtn').addEventListener('click', function() {
            var execContainer = document.getElementById('executiveDropdown');
            execContainer.querySelector('.dropdown-input span').textContent = 'All Executives';
            var execChips = document.getElementById('executiveChips');
            if (execChips) execChips.innerHTML = '';
            execContainer.querySelectorAll('.option-item').forEach(function(opt) {
                opt.classList.remove('selected');
                var check = opt.querySelector('.check');
                if (check) check.textContent = '';
                if (opt.getAttribute('data-value') == '0') {
                    opt.classList.add('selected');
                    var check2 = opt.querySelector('.check');
                    if (check2) check2.textContent = '✓';
                }
            });
            
            var couponContainer = document.getElementById('couponDropdown');
            couponContainer.querySelector('.dropdown-input span').textContent = 'All Coupons';
            var couponChips = document.getElementById('couponChips');
            if (couponChips) couponChips.innerHTML = '';
            couponContainer.querySelectorAll('.option-item').forEach(function(opt) {
                opt.classList.remove('selected');
                var check = opt.querySelector('.check');
                if (check) check.textContent = '';
                if (opt.getAttribute('data-value') == 'all') {
                    opt.classList.add('selected');
                    var check2 = opt.querySelector('.check');
                    if (check2) check2.textContent = '✓';
                }
            });
            
            document.getElementById('date_filter').value = 'all';
            document.getElementById('from_date').value = '';
            document.getElementById('to_date').value = '';
            document.querySelector('.custom-date-group').classList.remove('show');
            
            applyFilters();
        });

        // Date filter change handler - already in inline onchange
        // No need for additional event listener

        $(document).ready(function() {
            if (document.getElementById('date_filter').value === 'custom') {
                document.querySelector('.custom-date-group').classList.add('show');
            }
            setTimeout(applyFilters, 100);
        });
    </script>
</body>
</html>