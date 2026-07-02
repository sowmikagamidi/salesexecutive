<?php
// view_all_executive.php
include_once 'config.php';

// Get parameters
$type = isset($_GET['type']) ? $_GET['type'] : 'orders';
$executive_id = isset($_GET['executive']) ? (int)$_GET['executive'] : 19;
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
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

// Get executive name
function getExecutiveName($db, $executive_id) {
    $sql = "SELECT full_name FROM USERS WHERE user_id = :id";
    $result = $db->fetchDBQuery($sql, ['id' => $executive_id], true);
    return $result ? $result['full_name'] : 'Executive ' . $executive_id;
}

// Get coupons for dropdown - USING executive_id
function getExecutiveCoupons($db, $executive_id) {
    $sql = "SELECT coupon_id, coupon_code FROM TX_COUPON_CODES WHERE executive_id = :executive_id AND is_active = 1 ORDER BY coupon_code";
    return $db->fetchDBQuery($sql, ['executive_id' => $executive_id]);
}

$executive_name = getExecutiveName($db, $executive_id);
$date_condition = getDateCondition($date_filter, $from_date, $to_date);
$coupon_condition = $coupon_filter != 'all' ? "AND ph.coupon_id = " . (int)$coupon_filter : "";
$executive_coupons = getExecutiveCoupons($db, $executive_id);

$title = '';
$headers = [];
$rows = [];
$total_rows = 0;
$total_pages = 0;

try {
    if ($type == 'orders') {
        $title = 'All Orders - ' . $executive_name;
        
        // Get total count - USING executive_id
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
        $total_pages = ceil($total_rows / $limit);
        
        // Get paginated data - USING executive_id
        $sql = "
            SELECT 
                ph.order_id,
                u.full_name as student_name,
                ex.full_name as executive_name,
                cc.coupon_code,
                ROUND(ph.order_amount, 2) as amount,
                DATE_FORMAT(ph.order_date, '%d %b %Y') as order_date_formatted,
                ph.payment_status
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
            LIMIT :offset, :limit
        ";
        $rows = $db->fetchDBQuery($sql, [
            'executive_id' => $executive_id,
            'offset' => $offset,
            'limit' => $limit
        ]);
        $headers = ['Order ID', 'Student', 'Coupon Code', 'Executive', 'Amount', 'Date', 'Status'];
        
    } elseif ($type == 'coupons') {
        $title = 'All Coupons - ' . $executive_name;
        
        // Get total count - USING executive_id
        $count_sql = "
            SELECT COUNT(DISTINCT cc.coupon_id) as total
            FROM TX_COUPON_CODES cc
            LEFT JOIN TX_PURCHASE_HISTORY ph
                ON ph.coupon_id = cc.coupon_id
                AND ph.payment_status = 'S'
                AND ph.amount > 0
                $date_condition
            WHERE cc.executive_id = :executive_id
            AND cc.is_active = 1
            $coupon_condition
        ";
        $count_result = $db->fetchDBQuery($count_sql, ['executive_id' => $executive_id], true);
        $total_rows = $count_result['total'] ?? 0;
        $total_pages = ceil($total_rows / $limit);
        
        // Get paginated data - USING executive_id
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
            LIMIT :offset, :limit
        ";
        $rows = $db->fetchDBQuery($sql, [
            'executive_id' => $executive_id,
            'offset' => $offset,
            'limit' => $limit
        ]);
        $headers = ['Coupon Code', 'Issued On', 'Expiry Date', 'Total Used', 'Total Students', 'Sales (₹)', 'Commission (₹)'];
    }
} catch(Exception $e) {
    error_log("Error in view_all_executive: " . $e->getMessage());
    $rows = [];
    $total_rows = 0;
    $total_pages = 0;
}

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
    <script src="ajax_view.js"></script>
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
            <input type="hidden" name="executive" value="<?php echo $executive_id; ?>">
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
                <label class="filter-label">🏷️ Coupon Code</label>
                <div class="coupon-search">
                    <input type="text" id="couponSearch" placeholder="Search and select coupon..." value="<?php echo $coupon_display != 'All Coupons' ? $coupon_display : ''; ?>">
                    <span class="search-icon"><i class="fas fa-search"></i></span>
                </div>
                <input type="hidden" name="coupon_filter" id="filter_coupon_filter" value="<?php echo $coupon_filter; ?>">
                <div class="coupon-list" id="couponList">
                    <div class="coupon-item <?php echo $coupon_filter == 'all' ? 'selected' : ''; ?>" data-value="all">All Coupons</div>
                    <?php foreach ($executive_coupons as $coupon): ?>
                        <div class="coupon-item <?php echo $coupon_filter == $coupon['coupon_id'] ? 'selected' : ''; ?>" data-value="<?php echo $coupon['coupon_id']; ?>">
                            <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="selectedCouponDisplay" style="margin-top: 5px;">
                    <?php if ($coupon_filter != 'all'): ?>
                        <span class="selected-coupon"><?php echo $coupon_display; ?></span>
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
                <span class="filter-info">
                    <strong><?php echo getDateRangeDisplay($date_filter, $from_date, $to_date); ?></strong>
                    <?php if ($coupon_filter != 'all'): ?>
                        | Coupon: <strong><?php echo htmlspecialchars($coupon_display); ?></strong>
                    <?php endif; ?>
                    | Executive: <strong><?php echo htmlspecialchars($executive_name); ?></strong>
                </span>
                <button class="filter-btn" id="openPanel">
                    <i class="fas fa-filter"></i> Filters
                </button>
                <a href="salesexecutive_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>
        
        <div class="section">
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
            </div>
            
            <div class="count-info">
                Showing <strong><?php echo count($rows); ?></strong> of <strong><?php echo $total_rows; ?></strong> records 
                (Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages > 0 ? $total_pages : 1; ?></strong>)
            </div>
            
            <?php if (count($rows) > 0): ?>
            <div style="overflow-x: auto;">
                <table id="dataTable">
                    <thead>
                        <tr>
                            <?php foreach ($headers as $header): ?>
                            <th><?php echo $header; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if ($type == 'orders'): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong>#<?php echo $row['order_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($row['student_name'] ?? 'N/A'); ?></td>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($row['coupon_code'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($row['executive_name'] ?? 'N/A'); ?></td>
                                <td><?php echo formatCurrency($row['amount']); ?></td>
                                <td><?php echo $row['order_date_formatted']; ?></td>
                                <td><span class="status-badge success">Success</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php elseif ($type == 'coupons'): ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($row['coupon_code']); ?></span></td>
                                <td><?php echo $row['issued_on']; ?></td>
                                <td><?php echo $row['expiry_date']; ?></td>
                                <td><strong><?php echo $row['total_used']; ?></strong></td>
                                <td><strong><?php echo $row['total_students']; ?></strong></td>
                                <td><?php echo formatCurrency($row['sales_amount']); ?></td>
                                <td><?php echo formatCurrency($row['commission']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?type=<?php echo $type; ?>&executive=<?php echo $executive_id; ?>&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>&page=<?php echo $page-1; ?>">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1) {
                        echo '<a href="?type='.$type.'&executive='.$executive_id.'&date_filter='.$date_filter.'&coupon_filter='.$coupon_filter.($from_date ? '&from_date='.$from_date : '').($to_date ? '&to_date='.$to_date : '').'&page=1">1</a>';
                        if ($start_page > 2) {
                            echo '<span>...</span>';
                        }
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) {
                            echo '<span class="active">'.$i.'</span>';
                        } else {
                            echo '<a href="?type='.$type.'&executive='.$executive_id.'&date_filter='.$date_filter.'&coupon_filter='.$coupon_filter.($from_date ? '&from_date='.$from_date : '').($to_date ? '&to_date='.$to_date : '').'&page='.$i.'">'.$i.'</a>';
                        }
                    }
                    
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span>...</span>';
                        }
                        echo '<a href="?type='.$type.'&executive='.$executive_id.'&date_filter='.$date_filter.'&coupon_filter='.$coupon_filter.($from_date ? '&from_date='.$from_date : '').($to_date ? '&to_date='.$to_date : '').'&page='.$total_pages.'">'.$total_pages.'</a>';
                    }
                    ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?type=<?php echo $type; ?>&executive=<?php echo $executive_id; ?>&date_filter=<?php echo $date_filter; ?>&coupon_filter=<?php echo $coupon_filter; ?><?php echo $from_date ? '&from_date='.$from_date : ''; ?><?php echo $to_date ? '&to_date='.$to_date : ''; ?>&page=<?php echo $page+1; ?>">
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
</body>
</html>