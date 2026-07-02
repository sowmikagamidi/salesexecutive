<?php
$pageTitle = "Sales Executive Dashboard";
include('config.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pagename = 'cp_dashboard';

$default_board_id = '';

$executive_id = 19;

// Get filter parameters
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// =============================================
// DATABASE FUNCTIONS USING $db
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

// Function to get executive details
function getExecutiveDetails($db, $executive_id) {
    $sql = "
        SELECT 
            user_id,
            full_name AS executive_name,
            email_id,
            mobile_number,
            role
        FROM USERS 
        WHERE user_id = :executive_id
        AND user_type = 'SE'
    ";
    return $db->fetchDBQuery($sql, ['executive_id' => $executive_id], true);
}

// Function to get executive stats with filters - USING executive_id from coupons
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
    
    // Get active coupons count - USING executive_id
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

// Function to get coupon performance with filters - USING executive_id
function getCouponPerformance($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
    $date_condition = getDateCondition($date_filter, $from_date, $to_date);
    
    if ($coupon_filter == 'all') {
        $coupon_condition = "";
    } else {
        $coupon_condition = "AND ph.coupon_id = " . (int)$coupon_filter;
    }
    
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
        $sql .= " LIMIT " . (int)$limit;
    }

    $coupons = $db->fetchDBQuery($sql, [
        'executive_id' => $executive_id
    ]);

    $totalOrders = array_sum(array_column($coupons, 'total_used'));

    foreach ($coupons as &$coupon) {
        $coupon['usage_rate'] = $totalOrders > 0
            ? round(($coupon['total_used'] / $totalOrders) * 100)
            : 0;

        $coupon['sales_amount'] = (float)$coupon['sales_amount'];
        $coupon['commission'] = (float)$coupon['commission'];
    }

    return $coupons;
}

// Function to get orders with filters - USING executive_id
function getOrders($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, $limit = 5) {
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
        LIMIT " . (int)$limit . "
    ";
    
    $orders = $db->fetchDBQuery($sql, ['executive_id' => $executive_id]);
    
    foreach ($orders as &$order) {
        $order['amount'] = (float)$order['amount'];
    }
    
    return $orders;
}

// Function to get sales overview with filters - USING executive_id
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

// Function to get coupons for dropdown - USING executive_id
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

// ===== GET ALL DATA =====
$executive = getExecutiveDetails($db, $executive_id);
$stats = getExecutiveStats($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
$coupons = getCouponPerformance($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date, 5);
$orders = getOrders($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
$sales_overview = getSalesOverview($db, $executive_id, $date_filter, $coupon_filter, $from_date, $to_date);
$executive_coupons = getExecutiveCoupons($db, $executive_id);

// If no executive found
if (!$executive) {
    $executive = [
        'executive_name' => 'Rahul Sharma',
        'email_id' => '',
        'mobile_number' => '',
        'role' => 'Executive'
    ];
}

$executive_name = $executive['executive_name'] ?? 'Rahul Sharma';
$has_coupons = count($coupons) > 0;
$has_orders = count($orders) > 0;

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

// Show custom date fields if custom filter is selected
$show_custom_dates = ($date_filter == 'custom');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Executive Dashboard - <?php echo htmlspecialchars($executive_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="dashboard_ajax.js"></script>
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
            font-size: 22px;
            color: #057ab5;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-header .executive-badge {
            background: #667eea;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .dashboard-header .executive-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .dashboard-header .executive-info .email {
            color: #7f8c8d;
            font-size: 13px;
        }
        
        .filter-section .btn-apply {
            padding: 8px 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            align-self: center;
        }
        .filter-section .btn-apply:hover {
            background: #5a6fd6;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .filter-section .btn-apply:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .filter-section {
            background: white;
            padding: 18px 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 15px;
        }
        .filter-section .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .filter-section .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-section select, .filter-section input {
            padding: 8px 12px;
            border: 1px solid #dce1e8;
            border-radius: 8px;
            font-size: 13px;
            background: white;
            color: #2c3e50;
            min-width: 150px;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .filter-section select:hover, .filter-section input:hover {
            border-color: #667eea;
        }
        .filter-section select:focus, .filter-section input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .filter-section .filter-divider {
            width: 1px;
            height: 40px;
            background: #dce1e8;
        }
        .filter-section .filter-info {
            font-size: 13px;
            color: #7f8c8d;
            background: #f8f9fa;
            padding: 6px 15px;
            border-radius: 6px;
            display: inline-block;
            white-space: nowrap;
            align-self: center;
        }
        .filter-section .filter-info strong {
            color: #2c3e50;
        }
        .filter-section .btn-reset {
            padding: 8px 16px;
            background: #ecf0f1;
            color: #2c3e50;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            align-self: center;
        }
        .filter-section .btn-reset:hover {
            background: #dce1e8;
        }
        .filter-section .custom-date-group {
            display: none;
            align-items: flex-end;
            gap: 10px;
        }
        .filter-section .custom-date-group.show {
            display: flex;
        }
        .filter-section .custom-date-group input {
            min-width: 130px;
            cursor: pointer;
        }
        .filter-section .custom-date-group .filter-group {
            flex-direction: column;
        }
        
        .filter-loading {
            display: none;
            align-self: center;
            font-size: 13px;
            color: #667eea;
            font-weight: 500;
        }
        .filter-loading .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        .filter-loading.show {
            display: inline-block;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .filter-section {
                flex-direction: column;
                align-items: stretch;
                padding: 15px;
            }
            .filter-section .filter-group { width: 100%; }
            .filter-section .filter-divider { display: none; }
            .filter-section .btn-reset { width: 100%; text-align: center; }
            .filter-section .filter-info { text-align: center; white-space: normal; }
            .filter-section .custom-date-group { flex-wrap: wrap; width: 100%; }
            .filter-section .custom-date-group .filter-group { width: 100%; }
            .dashboard-header { flex-direction: column; align-items: stretch; }
            .filter-loading { text-align: center; }
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            transition: transform 0.2s, box-shadow 0.2s;
            border-left: 4px solid #667eea;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 6px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .stat-value {
            font-size: 26px;
            font-weight: 700;
            color: #2c3e50;
        }
        .stat-card .stat-sub {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 4px;
        }
        .stat-card.green { border-left-color: #27ae60; }
        .stat-card.green .stat-value { color: #27ae60; }
        .stat-card.blue { border-left-color: #3498db; }
        .stat-card.blue .stat-value { color: #3498db; }
        .stat-card.purple { border-left-color: #9b59b6; }
        .stat-card.purple .stat-value { color: #9b59b6; }
        .stat-card.orange { border-left-color: #e67e22; }
        .stat-card.orange .stat-value { color: #e67e22; }
        .stat-card.red { border-left-color: #e74c3c; }
        .stat-card.red .stat-value { color: #e74c3c; }
        
        .section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #ecf0f1;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #ecf0f1;
        }
        .section-header .section-title {
            border: none;
            margin: 0;
            padding: 0;
        }
        
        .chart-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        .chart-box { 
            background: #fafbfc; 
            padding: 20px; 
            border-radius: 10px;
            border: 1px solid #ecf0f1;
        }
        .chart-box canvas { max-height: 250px; }
        
        .summary-stats {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            gap: 15px;
            padding: 20px;
        }
        .summary-stats .stat-item {
            text-align: center;
            width: 100%;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .summary-stats .stat-item .label {
            font-size: 13px;
            color: #7f8c8d;
            font-weight: 500;
        }
        .summary-stats .stat-item .value {
            font-size: 28px;
            font-weight: 700;
            margin-top: 2px;
        }
        .summary-stats .stat-item .value.blue { color: #3498db; }
        .summary-stats .stat-item .value.green { color: #27ae60; }
        .summary-stats .stat-item .value.purple { color: #9b59b6; }
        
        table { width: 100%; border-collapse: collapse; }
        table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #ecf0f1;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 14px;
        }
        table tr:hover { background: #f8f9fa; }
        
        .btn {
            display: inline-block;
            padding: 8px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
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
        .btn-sm { padding: 5px 15px; font-size: 12px; }
        .btn-primary { background: #667eea; }
        .btn-primary:hover { background: #5a6fd6; }
        
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.success { background: #d4edda; color: #155724; }
        
        .coupon-code {
            font-weight: 600;
            color: #2c3e50;
            background: #f0f2f5;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-family: 'Courier New', monospace;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 10px; }
        
        .order-count-badge {
            font-size: 12px;
            color: #7f8c8d;
            background: #f8f9fa;
            padding: 4px 12px;
            border-radius: 12px;
            margin-left: 10px;
        }
        
        @media (max-width: 768px) {
            .chart-container { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .stat-card .stat-value { font-size: 20px; }
            table th, table td { padding: 8px 10px; font-size: 12px; }
            .section { padding: 15px; }
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
                📊 Sales Executive Dashboard
                <span class="executive-badge">👤 <?php echo htmlspecialchars($executive_name); ?></span>
            </h1>
            <div class="executive-info">
                <?php if (!empty($executive['email_id'])): ?>
                    <span class="email">📧 <?php echo htmlspecialchars($executive['email_id']); ?></span>
                <?php endif; ?>
                <?php if (!empty($executive['mobile_number'])): ?>
                    <span class="email">📞 <?php echo htmlspecialchars($executive['mobile_number']); ?></span>
                <?php endif; ?>
                <span class="email">🆔 ID: <?php echo $executive_id; ?></span>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form id="filterForm" style="display: contents;">
                <input type="hidden" name="executive" value="<?php echo $executive_id; ?>">
                
                <div class="filter-group">
                    <span class="filter-label">📅 Date Range</span>
                    <select name="date_filter" id="date_filter">
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
                
                <div class="custom-date-group <?php echo $show_custom_dates ? 'show' : ''; ?>">
                    <div class="filter-group">
                        <span class="filter-label">From</span>
                        <input type="date" name="from_date" id="from_date" value="<?php echo $from_date; ?>">
                    </div>
                    <div class="filter-group">
                        <span class="filter-label">To</span>
                        <input type="date" name="to_date" id="to_date" value="<?php echo $to_date; ?>">
                    </div>
                </div>
                
                <div class="filter-divider"></div>
                
                <div class="filter-group">
                    <span class="filter-label">🏷️ Coupon</span>
                    <select name="coupon_filter" id="coupon_filter">
                        <option value="all" <?php echo $coupon_filter == 'all' ? 'selected' : ''; ?>>All Coupons</option>
                        <?php foreach ($executive_coupons as $coupon): ?>
                            <option value="<?php echo $coupon['coupon_id']; ?>" <?php echo $coupon_filter == $coupon['coupon_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-divider"></div>
                
                <div class="filter-info" id="filterInfo">
                    <strong><?php echo getDateRangeDisplay($date_filter, $from_date, $to_date); ?></strong>
                    <?php if ($coupon_filter != 'all'): ?>
                        | Coupon: <strong><?php echo $coupon_display; ?></strong>
                    <?php endif; ?>
                </div>
                
                <div class="filter-loading" id="filterLoading">
                    <span class="spinner"></span> Loading...
                </div>
                
                <button type="button" class="btn-apply" id="showFilters">
                    <i class="fas fa-filter"></i> Apply
                </button>
                
                <a href="salesexecutive_dashboard.php" class="btn-reset">Reset</a>
            </form>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card blue">
                <div class="stat-label">Total Sales</div>
                <div class="stat-value" id="totalSales">₹ <?php echo number_format((float)$stats['total_sales'], 2); ?></div>
                <div class="stat-sub">Total Order Amount</div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Total Coupons</div>
                <div class="stat-value" id="totalCoupons"><?php echo $stats['active_coupons'] ?? 0; ?></div>
                <div class="stat-sub">Active Coupons</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-label">Total Orders</div>
                <div class="stat-value" id="totalOrders"><?php echo $stats['total_orders'] ?? 0; ?></div>
                <div class="stat-sub">Total Orders</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label">Total Commission</div>
                <div class="stat-value" id="totalCommission">₹ <?php echo number_format((float)$stats['total_commission'], 2); ?></div>
                <div class="stat-sub">25% Commission</div>
            </div>
            <div class="stat-card red">
                <div class="stat-label">Total Students</div>
                <div class="stat-value" id="totalStudents"><?php echo $stats['total_students'] ?? 0; ?></div>
                <div class="stat-sub">Unique Students</div>
            </div>
        </div>

        <!-- Sales Overview Chart -->
        <div class="section">
            <div class="section-title">📈 Sales & Commission Overview</div>
            <div class="chart-container">
                <div class="chart-box">
                    <canvas id="salesChart"></canvas>
                </div>
                
            </div>
        </div>

        <!-- Coupon Performance Table -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">🎫 Coupon Performance</div>
                <a href="view_all_executive.php?type=coupons&executive=<?php echo $executive_id; ?>&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>" class="btn btn-sm btn-primary" target="_blank">View All Coupons →</a>
            </div>
            
            <div style="overflow-x: auto;">
                <table id="couponTable">
                    <thead>
                        <tr>
                            <th>Coupon Code</th>
                            <th>Issued On</th>
                            <th>Expiry Date</th>
                            <th>Total Used</th>
                            <th>Total Students</th>
                            <th>Sales (₹)</th>
                            <th>Commission (₹)</th>
                        </tr>
                    </thead>
                    <tbody id="couponTableBody">
                        <?php if ($has_coupons): ?>
                            <?php foreach ($coupons as $coupon): 
                                $usage_rate = min($coupon['usage_rate'], 100);
                                $bar_color = $usage_rate > 70 ? 'green' : ($usage_rate > 40 ? 'orange' : 'red');
                            ?>
                            <tr>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($coupon['coupon_code']); ?></span></td>
                                <td><?php echo $coupon['issued_on']; ?></td>
                                <td><?php echo $coupon['expiry_date']; ?></td>
                                <td><strong><?php echo $coupon['total_used']; ?></strong></td>
                                <td><strong><?php echo $coupon['total_students']; ?></strong></td>
                                <td>₹ <?php echo number_format((float)$coupon['sales_amount'], 2); ?></td>
                                <td>₹ <?php echo number_format((float)$coupon['commission'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noCouponRow">
                                <td colspan="7" style="text-align: center; padding: 30px; color: #7f8c8d;">
                                    <div class="empty-state">
                                        <div class="icon">🎫</div>
                                        <p>No coupons found for <?php echo htmlspecialchars($executive_name); ?>.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Orders Table (Limited to 5) -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    🛒 Orders 
                    <span class="order-count-badge">Latest 5</span>
                </div>
                <a href="view_all_executive.php?type=orders&executive=<?php echo $executive_id; ?>&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>" class="btn btn-sm btn-primary" target="_blank">View All Orders →</a>
            </div>
            
            <div style="overflow-x: auto;">
                <table id="ordersTable">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Student</th>
                            <th>Coupon Code</th>
                            <th>Executive</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <?php if ($has_orders): ?>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong>#<?php echo $order['order_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($order['student_name'] ?? 'N/A'); ?></td>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($order['coupon_code'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($order['executive_name'] ?? 'N/A'); ?></td>
                                <td>₹ <?php echo number_format((float)$order['amount'], 2); ?></td>
                                <td><?php echo $order['order_date_formatted']; ?></td>
                                <td><span class="status-badge success">Success</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: #7f8c8d;">
                                    <div class="empty-state">
                                        <div class="icon">📋</div>
                                        <p>No orders found for <?php echo htmlspecialchars($executive_name); ?>.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Initialize Chart
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
                    backgroundColor: 'rgba(52, 152, 219, 0.08)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    borderWidth: 2
                }, {
                    label: 'Commission (₹)',
                    data: commissions,
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.08)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#27ae60',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    borderWidth: 2
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
                            padding: 20,
                            font: { size: 12 }
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
                                return '₹' + parseFloat(value).toFixed(2);
                            },
                            font: { size: 11 }
                        },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 } }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
    </script>
</body>
</html>