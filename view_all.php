<?php
// view_all.php
include_once 'config.php';

$type = isset($_GET['type']) ? $_GET['type'] : 'executives';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$executive_filter = isset($_GET['executive_filter']) ? (int)$_GET['executive_filter'] : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

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

// Get all executives for dropdown - USING $db
function getAllExecutivesList($db) {
    $sql = "SELECT user_id, full_name FROM USERS WHERE user_type = 'SE' AND is_deleted = 0 AND user_status = 'A' ORDER BY full_name";
    return $db->fetchDBQuery($sql);
}

$executives_list = getAllExecutivesList($db);
$date_condition = getDateCondition($date_filter, $from_date, $to_date);
$coupon_condition = $coupon_filter != 'all' ? "AND ph.coupon_id = " . (int)$coupon_filter : "";
$executive_condition = $executive_filter > 0 ? "AND cc.executive_id = " . (int)$executive_filter : "";

// Get all coupons for dropdown with search
function getAllCouponsForDropdown($db, $executive_filter) {
    $sql = "SELECT coupon_id, coupon_code FROM TX_COUPON_CODES WHERE is_active = 1 AND coupon_code LIKE 'S%'";
    if ($executive_filter > 0) {
        $sql .= " AND executive_id = " . (int)$executive_filter;
    }
    $sql .= " ORDER BY coupon_code";
    return $db->fetchDBQuery($sql);
}

$all_coupons_list = getAllCouponsForDropdown($db, $executive_filter);

$title = '';
$headers = [];
$rows = [];
$total_rows = 0;
$total_pages = 0;

try {
    if ($type == 'executives') {
        $title = 'All Executives';
        
        // Build WHERE conditions for executives
        $exec_where = "u.user_type = 'SE' AND u.is_deleted = 0";
        
        // If executive filter is applied, show only that executive
        if ($executive_filter > 0) {
            $exec_where .= " AND u.user_id = " . (int)$executive_filter;
        }
        
        // If coupon filter is applied, filter executives by coupon
        if ($coupon_filter != 'all') {
            $exec_where .= " AND cc.coupon_id = " . (int)$coupon_filter;
        }
        
        // Get total count
        $count_sql = "
            SELECT COUNT(DISTINCT u.user_id) as total
            FROM USERS u
            LEFT JOIN TX_PURCHASE_HISTORY ph ON u.user_id = ph.created_by 
                AND ph.payment_status = 'S' 
                AND ph.amount > 0
                $date_condition
            LEFT JOIN TX_COUPON_CODES cc ON u.user_id = cc.executive_id AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
            WHERE $exec_where
        ";
        $count_result = $db->fetchDBQuery($count_sql, [], true);
        $total_rows = $count_result['total'] ?? 0;
        $total_pages = ceil($total_rows / $limit);
        
        // Get paginated data
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
            LEFT JOIN TX_COUPON_CODES cc ON u.user_id = cc.executive_id AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
            WHERE $exec_where
            GROUP BY u.user_id
            HAVING total_sales > 0 OR active_coupons > 0
            ORDER BY total_sales DESC
            LIMIT :offset, :limit
        ";
        $rows = $db->fetchDBQuery($sql, [
            'offset' => $offset,
            'limit' => $limit
        ]);
        $headers = ['#', 'Executive Name', 'Total Orders', 'Sales (Order Amount)', 'Commission (25%)', 'Active Coupons'];
        
    } elseif ($type == 'orders') {
        $title = 'All Orders';
        
        // Build WHERE conditions for orders
        $order_where = "ph.payment_status = 'S' AND ph.amount > 0 AND cc.coupon_code LIKE 'S%'";
        
        // If executive filter is applied
        if ($executive_filter > 0) {
            $order_where .= " AND cc.executive_id = " . (int)$executive_filter;
        }
        
        // If coupon filter is applied
        if ($coupon_filter != 'all') {
            $order_where .= " AND ph.coupon_id = " . (int)$coupon_filter;
        }
        
        // Get total count
        $count_sql = "
            SELECT COUNT(*) as total
            FROM TX_PURCHASE_HISTORY ph
            LEFT JOIN TX_COUPON_CODES cc ON ph.coupon_id = cc.coupon_id
            LEFT JOIN USERS ex ON cc.executive_id = ex.user_id
            WHERE $order_where
            $date_condition
        ";
        $count_result = $db->fetchDBQuery($count_sql, [], true);
        $total_rows = $count_result['total'] ?? 0;
        $total_pages = ceil($total_rows / $limit);
        
        // Get paginated data
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
            WHERE $order_where
            $date_condition
            ORDER BY ph.order_date DESC
            LIMIT :offset, :limit
        ";
        $rows = $db->fetchDBQuery($sql, [
            'offset' => $offset,
            'limit' => $limit
        ]);
        $headers = ['Order ID', 'Student', 'Executive (Coupon)', 'Amount', 'Date'];
        
    } elseif ($type == 'coupons') {
        $title = 'All Coupons';
        
        // Build WHERE conditions for coupons
        $coupon_where = "cc.is_active = 1 AND cc.coupon_code LIKE 'S%'";
        
        // If executive filter is applied
        if ($executive_filter > 0) {
            $coupon_where .= " AND cc.executive_id = " . (int)$executive_filter;
        }
        
        // If coupon filter is applied
        if ($coupon_filter != 'all') {
            $coupon_where .= " AND cc.coupon_id = " . (int)$coupon_filter;
        }
        
        // Get total count
        $count_sql = "
            SELECT COUNT(DISTINCT cc.coupon_id) as total
            FROM TX_COUPON_CODES cc
            LEFT JOIN TX_PURCHASE_HISTORY ph ON cc.coupon_id = ph.coupon_id 
                AND ph.payment_status = 'S' 
                AND ph.amount > 0
                $date_condition
            WHERE $coupon_where
        ";
        $count_result = $db->fetchDBQuery($count_sql, [], true);
        $total_rows = $count_result['total'] ?? 0;
        $total_pages = ceil($total_rows / $limit);
        
        // Get paginated data
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
            WHERE $coupon_where
            GROUP BY cc.coupon_id
            ORDER BY total_sales DESC
            LIMIT :offset, :limit
        ";
        $rows = $db->fetchDBQuery($sql, [
            'offset' => $offset,
            'limit' => $limit
        ]);
        $headers = ['Coupon Code', 'Used', 'Sales (₹)', 'Commission (₹)'];
    }
} catch(Exception $e) {
    error_log("Error in view_all: " . $e->getMessage());
    $rows = [];
    $total_rows = 0;
    $total_pages = 0;
}

// Get executive name for display
function getExecutiveName($db, $executive_id) {
    if ($executive_id == 0) {
        return 'All Executives';
    }
    $sql = "SELECT full_name FROM USERS WHERE user_id = :id";
    $result = $db->fetchDBQuery($sql, ['id' => $executive_id], true);
    return $result ? $result['full_name'] : 'All Executives';
}

$executive_name = getExecutiveName($db, $executive_filter);

// Get coupon name for display
function getCouponName($db, $coupon_id) {
    if ($coupon_id == 'all') {
        return 'All Coupons';
    }
    $sql = "SELECT coupon_code FROM TX_COUPON_CODES WHERE coupon_id = :id";
    $result = $db->fetchDBQuery($sql, ['id' => $coupon_id], true);
    return $result ? $result['coupon_code'] : 'All Coupons';
}
$coupon_name = getCouponName($db, $coupon_filter);

$show_custom_dates = ($date_filter == 'custom');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            padding: 20px;
            transition: margin-right 0.3s ease;
        }
        body.panel-open {
            margin-right: 380px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            font-size: 22px;
            color: #057ab5;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .header .back-btn {
            padding: 8px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .header .back-btn:hover {
            background: #5a6fd6;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .header .filter-btn {
            padding: 8px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header .filter-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }
        .header .filter-btn.active {
            background: #e74c3c;
        }
        .header .filter-btn.active:hover {
            background: #c0392b;
        }
        .header .filter-info {
            font-size: 13px;
            color: #7f8c8d;
            background: #f8f9fa;
            padding: 6px 15px;
            border-radius: 6px;
        }
        .header .filter-info strong {
            color: #2c3e50;
        }
        
        .filter-panel {
            position: fixed;
            top: 0;
            right: -380px;
            width: 380px;
            height: 100%;
            background: white;
            box-shadow: -2px 0 20px rgba(0,0,0,0.15);
            transition: right 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            padding: 25px;
        }
        .filter-panel.open {
            right: 0;
        }
        .filter-panel .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
            margin-bottom: 20px;
        }
        .filter-panel .panel-header h2 {
            font-size: 20px;
            color: #2c3e50;
        }
        .filter-panel .panel-header .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #7f8c8d;
            cursor: pointer;
            transition: color 0.2s;
        }
        .filter-panel .panel-header .close-btn:hover {
            color: #2c3e50;
        }
        .filter-panel .filter-group {
            margin-bottom: 20px;
        }
        .filter-panel .filter-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-panel select, .filter-panel input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dce1e8;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: #2c3e50;
            transition: border-color 0.2s;
        }
        .filter-panel select:focus, .filter-panel input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .filter-panel .custom-date-group {
            display: none;
        }
        .filter-panel .custom-date-group.show {
            display: block;
        }
        .filter-panel .custom-date-group input {
            margin-bottom: 10px;
        }
        .filter-panel .coupon-search {
            position: relative;
        }
        .filter-panel .coupon-search input {
            padding-right: 35px;
        }
        .filter-panel .coupon-search .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
        }
        .filter-panel .coupon-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #dce1e8;
            border-radius: 8px;
            margin-top: 5px;
            display: none;
        }
        .filter-panel .coupon-list.show {
            display: block;
        }
        .filter-panel .coupon-item {
            padding: 8px 12px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #f0f2f5;
        }
        .filter-panel .coupon-item:hover {
            background: #f8f9fa;
        }
        .filter-panel .coupon-item.selected {
            background: #667eea;
            color: white;
        }
        .filter-panel .coupon-item:last-child {
            border-bottom: none;
        }
        .filter-panel .selected-coupon {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            margin-top: 5px;
        }
        .filter-panel .searchable-executive {
            position: relative;
        }
        .filter-panel .searchable-executive input {
            padding-right: 35px;
        }
        .filter-panel .searchable-executive .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
        }
        .filter-panel .executive-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #dce1e8;
            border-radius: 8px;
            margin-top: 5px;
            display: none;
        }
        .filter-panel .executive-list.show {
            display: block;
        }
        .filter-panel .executive-item {
            padding: 8px 12px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #f0f2f5;
        }
        .filter-panel .executive-item:hover {
            background: #f8f9fa;
        }
        .filter-panel .executive-item.selected {
            background: #667eea;
            color: white;
        }
        .filter-panel .executive-item:last-child {
            border-bottom: none;
        }
        .filter-panel .selected-executive {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            margin-top: 5px;
        }
        .filter-panel .panel-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
        }
        .filter-panel .btn-apply {
            flex: 1;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-panel .btn-apply:hover {
            background: #5a6fd6;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .filter-panel .btn-apply:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .filter-panel .btn-reset-filter {
            padding: 10px 20px;
            background: #ecf0f1;
            color: #2c3e50;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-panel .btn-reset-filter:hover {
            background: #dce1e8;
        }
        
        .panel-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            z-index: 999;
        }
        .panel-overlay.show {
            display: block;
        }
        
        .section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: relative;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #ecf0f1;
        }
        
        table { width: 100%; border-collapse: collapse; }
        table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #ecf0f1;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 14px;
        }
        table tr:hover { background: #f8f9fa; }
        
        .coupon-code {
            font-weight: 600;
            color: #2c3e50;
            background: #f0f2f5;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-family: 'Courier New', monospace;
        }
        
        .executive-rank {
            display: inline-block;
            width: 28px;
            height: 28px;
            line-height: 28px;
            text-align: center;
            background: #f0f2f5;
            border-radius: 50%;
            font-weight: 600;
            font-size: 13px;
            color: #2c3e50;
        }
        .executive-rank.gold { background: #ffd700; color: #856404; }
        .executive-rank.silver { background: #c0c0c0; color: #495057; }
        .executive-rank.bronze { background: #cd7f32; color: white; }
        
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.success { background: #d4edda; color: #155724; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 15px; }
        
        .count-info {
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 15px;
        }
        .count-info strong {
            color: #2c3e50;
        }
        
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-info {
            font-size: 14px;
            color: #7f8c8d;
        }
        .pagination-info strong {
            color: #2c3e50;
        }
        .pagination {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 14px;
            border: 1px solid #dce1e8;
            border-radius: 6px;
            text-decoration: none;
            color: #2c3e50;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .pagination .disabled:hover {
            background: white;
            color: #2c3e50;
            border-color: #dce1e8;
        }
        
        .loading-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            z-index: 10;
            justify-content: center;
            align-items: center;
            border-radius: 12px;
        }
        .loading-overlay.show {
            display: flex;
        }
        .loading-overlay .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: stretch; }
            .header .filter-info { text-align: center; }
            .header .header-actions { justify-content: center; }
            table th, table td { padding: 8px 10px; font-size: 12px; }
            .section { padding: 15px; }
            .filter-panel { width: 100%; right: -100%; }
            body.panel-open { margin-right: 0; }
            .pagination-container { flex-direction: column; align-items: center; }
            .pagination-info { text-align: center; }
        }
    </style>
</head>
<body>
    <div class="panel-overlay" id="panelOverlay"></div>
    
    <div class="filter-panel" id="filterPanel">
        <div class="panel-header">
            <h2><i class="fas fa-filter"></i> Filters</h2>
            <button class="close-btn" id="closePanel"><i class="fas fa-times"></i></button>
        </div>
        
        <form id="filterForm">
            <input type="hidden" name="type" value="<?php echo $type; ?>">
            <input type="hidden" name="page" id="pageInput" value="1">
            
            <div class="filter-group">
                <label class="filter-label">📅 Date Range</label>
                <select name="date_filter" id="filter_date_filter">
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
            
            <div class="filter-group custom-date-group <?php echo $show_custom_dates ? 'show' : ''; ?>" id="customDateGroup">
                <label class="filter-label">From</label>
                <input type="date" name="from_date" id="filter_from_date" value="<?php echo $from_date; ?>">
                <label class="filter-label" style="margin-top: 10px;">To</label>
                <input type="date" name="to_date" id="filter_to_date" value="<?php echo $to_date; ?>">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">👤 Executive Name</label>
                <div class="searchable-executive">
                    <input type="text" id="executiveSearch" placeholder="Search executive..." value="<?php echo $executive_name != 'All Executives' ? $executive_name : ''; ?>">
                    <span class="search-icon"><i class="fas fa-search"></i></span>
                </div>
                <input type="hidden" name="executive_filter" id="filter_executive_filter" value="<?php echo $executive_filter; ?>">
                <div class="executive-list" id="executiveList">
                    <div class="executive-item <?php echo $executive_filter == 0 ? 'selected' : ''; ?>" data-value="0">All Executives</div>
                    <?php foreach ($executives_list as $exec): ?>
                        <div class="executive-item <?php echo $executive_filter == $exec['user_id'] ? 'selected' : ''; ?>" data-value="<?php echo $exec['user_id']; ?>">
                            <?php echo htmlspecialchars($exec['full_name']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="selectedExecutiveDisplay" style="margin-top: 5px;">
                    <?php if ($executive_filter > 0): ?>
                        <span class="selected-executive"><?php echo htmlspecialchars($executive_name); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">🏷️ Coupon</label>
                <div class="coupon-search">
                    <input type="text" id="couponSearch" placeholder="Search coupon..." value="<?php echo $coupon_name != 'All Coupons' ? $coupon_name : ''; ?>">
                    <span class="search-icon"><i class="fas fa-search"></i></span>
                </div>
                <input type="hidden" name="coupon_filter" id="filter_coupon_filter" value="<?php echo $coupon_filter; ?>">
                <div class="coupon-list" id="couponList">
                    <div class="coupon-item <?php echo $coupon_filter == 'all' ? 'selected' : ''; ?>" data-value="all">All Coupons</div>
                    <?php foreach ($all_coupons_list as $coupon): ?>
                        <div class="coupon-item <?php echo $coupon_filter == $coupon['coupon_id'] ? 'selected' : ''; ?>" data-value="<?php echo $coupon['coupon_id']; ?>">
                            <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="selectedCouponDisplay" style="margin-top: 5px;">
                    <?php if ($coupon_filter != 'all'): ?>
                        <span class="selected-coupon"><?php echo htmlspecialchars($coupon_name); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="panel-actions">
                <button type="button" class="btn-apply" id="applyFilters">Apply Filters</button>
                <button type="button" class="btn-reset-filter" id="resetFilters">Reset</button>
            </div>
        </form>
    </div>

    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-list"></i> <?php echo $title; ?>
                <span style="background: #667eea; color: white; padding: 2px 12px; border-radius: 12px; font-size: 14px;">
                    <?php echo $total_rows; ?>
                </span>
            </h1>
            <div class="header-actions">
                
                <button class="filter-btn" id="openPanel">
                    <i class="fas fa-filter"></i> Filters
                </button>
               
            </div>
        </div>
        
        <div class="section">
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
            </div>
            
            
            <?php if (count($rows) > 0): ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($headers as $header): ?>
                            <th><?php echo $header; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($type == 'executives'): ?>
                            <?php foreach ($rows as $index => $row): 
                                $rank = (($page - 1) * $limit) + $index + 1;
                                $rank_class = $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : ($rank == 3 ? 'bronze' : ''));
                            ?>
                            <tr>
                                <td><span class="executive-rank <?php echo $rank_class; ?>"><?php echo $rank; ?></span></td>
                                <td><strong><?php echo htmlspecialchars($row['executive_name']); ?></strong></td>
                                <td><?php echo $row['total_orders']; ?></td>
                                <td><?php echo formatCurrency($row['total_sales']); ?></td>
                                <td><?php echo formatCurrency($row['total_commission']); ?></td>
                                <td><?php echo $row['active_coupons']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php elseif ($type == 'orders'): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong>#<?php echo $row['order_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($row['student_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['executive_name'] ?? 'N/A'); ?> <span class="coupon-code">(<?php echo htmlspecialchars($row['coupon_code'] ?? 'N/A'); ?>)</span></td>
                                <td><?php echo formatCurrency($row['amount']); ?></td>
                                <td><?php echo $row['order_date_formatted']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php elseif ($type == 'coupons'): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($row['coupon_code']); ?></span></td>
                                <td><strong><?php echo $row['total_used']; ?></strong></td>
                                <td><?php echo formatCurrency($row['total_sales']); ?></td>
                                <td><?php echo formatCurrency($row['total_commission']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <strong><?php echo count($rows); ?></strong> of <strong><?php echo $total_rows; ?></strong> records
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?type=<?php echo $type; ?>&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $executive_filter > 0 ? '&executive_filter='.$executive_filter : ''; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>&page=<?php echo $page-1; ?>">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1) {
                        echo '<a href="?type='.$type.'&date_filter='.$date_filter.'&coupon_filter='.$coupon_filter.($executive_filter > 0 ? '&executive_filter='.$executive_filter : '').($from_date ? '&from_date='.$from_date : '').($to_date ? '&to_date='.$to_date : '').'&page=1">1</a>';
                        if ($start_page > 2) {
                            echo '<span>...</span>';
                        }
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) {
                            echo '<span class="active">'.$i.'</span>';
                        } else {
                            echo '<a href="?type='.$type.'&date_filter='.$date_filter.'&coupon_filter='.$coupon_filter.($executive_filter > 0 ? '&executive_filter='.$executive_filter : '').($from_date ? '&from_date='.$from_date : '').($to_date ? '&to_date='.$to_date : '').'&page='.$i.'">'.$i.'</a>';
                        }
                    }
                    
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span>...</span>';
                        }
                        echo '<a href="?type='.$type.'&date_filter='.$date_filter.'&coupon_filter='.$coupon_filter.($executive_filter > 0 ? '&executive_filter='.$executive_filter : '').($from_date ? '&from_date='.$from_date : '').($to_date ? '&to_date='.$to_date : '').'&page='.$total_pages.'">'.$total_pages.'</a>';
                    }
                    ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?type=<?php echo $type; ?>&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $executive_filter > 0 ? '&executive_filter='.$executive_filter : ''; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>&page=<?php echo $page+1; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <p>No records found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // =============================================
            // RESET FILTERS ON PAGE REFRESH
            // =============================================
            // Check if page was refreshed
            if (performance.navigation.type === 1 || performance.getEntriesByType("navigation")[0].type === "reload") {
                // Page was refreshed - reset filters and redirect to default view
                var currentUrl = window.location.href;
                var baseUrl = currentUrl.split('?')[0];
                window.location.href = baseUrl + '?type=<?php echo $type; ?>';
                return;
            }

            // Also check if there are any filter parameters in URL
            var urlParams = new URLSearchParams(window.location.search);
            var hasFilters = urlParams.has('date_filter') || urlParams.has('coupon_filter') || 
                           urlParams.has('executive_filter') || urlParams.has('from_date') || 
                           urlParams.has('to_date') || urlParams.has('page');

            // If no filters and not on page 1, reset to page 1
            if (!hasFilters) {
                var pageParam = urlParams.get('page');
                if (pageParam && pageParam > 1) {
                    window.location.href = window.location.pathname + '?type=<?php echo $type; ?>';
                }
            }

            // =============================================
            // FILTER PANEL FUNCTIONS
            // =============================================
            
            // Open panel
            $('#openPanel').on('click', function() {
                $('#filterPanel').addClass('open');
                $('#panelOverlay').addClass('show');
                $('body').addClass('panel-open');
                $(this).addClass('active').html('<i class="fas fa-times"></i> Close');
            });

            // Close panel
            function closePanel() {
                $('#filterPanel').removeClass('open');
                $('#panelOverlay').removeClass('show');
                $('body').removeClass('panel-open');
                $('#openPanel').removeClass('active').html('<i class="fas fa-filter"></i> Filters');
            }

            $('#closePanel, #panelOverlay').on('click', function() {
                closePanel();
            });

            // Show/hide custom date fields
            $('#filter_date_filter').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#customDateGroup').addClass('show');
                } else {
                    $('#customDateGroup').removeClass('show');
                }
            });

            // Executive search
            $('#executiveSearch').on('keyup', function() {
                var search = $(this).val().toLowerCase().trim();
                $('#executiveList .executive-item').each(function() {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(search) > -1);
                });
                if ($(this).val().length > 0) {
                    $('#executiveList').addClass('show');
                } else {
                    $('#executiveList .executive-item').show();
                    $('#executiveList').removeClass('show');
                }
            });

            // Show executive list on focus
            $('#executiveSearch').on('focus', function() {
                $('#executiveList .executive-item').show();
                $('#executiveList').addClass('show');
            });

            // Hide executive list on blur
            $('#executiveSearch').on('blur', function() {
                setTimeout(function() {
                    $('#executiveList').removeClass('show');
                }, 300);
            });

            // Select executive from list and update coupon dropdown dynamically
            $('#executiveList .executive-item').on('click', function() {
                var value = $(this).data('value');
                var text = $(this).text();
                
                $('#executiveList .executive-item').removeClass('selected');
                $(this).addClass('selected');
                
                $('#filter_executive_filter').val(value);
                $('#executiveSearch').val(text);
                if (value == '0') {
                    $('#selectedExecutiveDisplay').html('');
                } else {
                    $('#selectedExecutiveDisplay').html('<span class="selected-executive">' + text + '</span>');
                }
                $('#executiveList').removeClass('show');
                
                // Update coupon dropdown based on selected executive
                updateCouponDropdown(value);
            });

            // Coupon search
            $('#couponSearch').on('keyup', function() {
                var search = $(this).val().toLowerCase().trim();
                $('#couponList .coupon-item').each(function() {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(search) > -1);
                });
                if ($(this).val().length > 0) {
                    $('#couponList').addClass('show');
                }
            });

            // Show coupon list on focus
            $('#couponSearch').on('focus', function() {
                $('#couponList .coupon-item').show();
                $('#couponList').addClass('show');
            });

            // Hide coupon list on blur
            $('#couponSearch').on('blur', function() {
                setTimeout(function() {
                    $('#couponList').removeClass('show');
                }, 300);
            });

            // Select coupon from list
            $('#couponList .coupon-item').on('click', function() {
                var value = $(this).data('value');
                var text = $(this).text();
                
                $('#couponList .coupon-item').removeClass('selected');
                $(this).addClass('selected');
                
                $('#filter_coupon_filter').val(value);
                $('#couponSearch').val(text);
                if (value == 'all') {
                    $('#selectedCouponDisplay').html('');
                } else {
                    $('#selectedCouponDisplay').html('<span class="selected-coupon">' + text + '</span>');
                }
                $('#couponList').removeClass('show');
            });

            // Function to update coupon dropdown based on executive
            function updateCouponDropdown(executiveId) {
                $.ajax({
                    url: 'ajax_get_coupons.php',
                    type: 'GET',
                    data: { executive_id: executiveId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var select = $('#filter_coupon_filter');
                            select.empty();
                            select.append('<option value="all">All Coupons</option>');
                            $.each(response.coupons, function(index, coupon) {
                                select.append('<option value="' + coupon.coupon_id + '">' + coupon.coupon_code + '</option>');
                            });
                            // Preserve selected coupon if it exists in the new list
                            var currentCoupon = '<?php echo $coupon_filter; ?>';
                            if (currentCoupon != 'all') {
                                var exists = false;
                                $.each(response.coupons, function(index, coupon) {
                                    if (coupon.coupon_id == currentCoupon) {
                                        exists = true;
                                    }
                                });
                                if (exists) {
                                    select.val(currentCoupon);
                                } else {
                                    select.val('all');
                                }
                            }
                        }
                    },
                    error: function() {
                        console.error('Error fetching coupons');
                    }
                });
            }

            // Apply filters - Redirect to page with filters
            $('#applyFilters').on('click', function() {
                var formData = $('#filterForm').serialize();
                
                $('#loadingOverlay').addClass('show');
                $('#applyFilters').prop('disabled', true).text('Applying...');
                
                $('#pageInput').val(1);
                
                var url = 'view_all.php?' + formData;
                window.location.href = url;
            });

            // Reset filters - Redirect to default page
            $('#resetFilters').on('click', function() {
                var type = $('#filterForm input[name="type"]').val();
                window.location.href = 'view_all.php?type=' + type;
            });
        });
    </script>
</body>
</html>