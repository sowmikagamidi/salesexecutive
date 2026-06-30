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

function formatCurrency($amount) {
    return '₹ ' . number_format((float)$amount, 2);
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

// Get executive name
function getExecutiveName($pdo, $executive_id) {
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $executive_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['full_name'] : 'Executive ' . $executive_id;
    } catch(PDOException $e) {
        return 'Executive ' . $executive_id;
    }
}

$executive_name = getExecutiveName($pdo, $executive_id);
$date_condition = getDateCondition($date_filter, $from_date, $to_date);
$coupon_condition = $coupon_filter != 'all' ? "AND ph.coupon_id = " . (int)$coupon_filter : "";
$executive_condition = "AND ph.created_by = " . (int)$executive_id;

$title = '';
$headers = [];
$rows = [];

try {
    if ($type == 'orders') {
        $title = 'All Orders - ' . $executive_name;
        $sql = "
            SELECT 
                ph.order_id,
                u.full_name as student_name,
                ex.full_name as executive_name,
                cc.coupon_code,
                ROUND(ph.order_amount, 2) as amount,
                DATE_FORMAT(ph.order_date, '%d %b %Y') as order_date_formatted,
                ph.payment_status
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':executive_id' => $executive_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Order ID', 'Student', 'Coupon Code', 'Executive', 'Amount', 'Date', 'Status'];
    } elseif ($type == 'coupons') {
        $title = 'All Coupons - ' . $executive_name;
        $sql = "
            SELECT 
                cc.coupon_code,
                cc.coupon_id,
                DATE_FORMAT(cc.created_dtm, '%d %b %Y') as issued_on,
                DATE_FORMAT(cc.expires_at, '%d %b %Y') as expiry_date,
                COUNT(ph.order_id) as total_used,
                COUNT(DISTINCT ph.student_id) as total_students,
                COALESCE(ROUND(SUM(ph.order_amount), 2), 0) as sales_amount,
                COALESCE(ROUND(SUM(ph.order_amount * 0.25), 2), 0) as commission,
                CASE 
                    WHEN COUNT(ph.order_id) > 0 
                    THEN ROUND((COUNT(ph.order_id) / 
                        NULLIF((SELECT COUNT(*) FROM tx_purchase_history 
                         WHERE payment_status = 'S' AND amount > 0 AND created_by = :executive_id $date_condition), 0)) * 100, 0)
                    ELSE 0 
                END as usage_rate
            FROM tx_coupon_codes cc
            LEFT JOIN tx_purchase_history ph ON cc.coupon_id = ph.coupon_id 
                AND ph.payment_status = 'S' 
                AND ph.amount > 0
                AND ph.created_by = :executive_id
                $date_condition
                $coupon_condition
            WHERE cc.is_active = 1
            AND cc.created_by = :executive_id
            GROUP BY cc.coupon_id
            ORDER BY total_used DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':executive_id' => $executive_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = ['Coupon Code', 'Issued On', 'Expiry Date', 'Total Used', 'Total Students', 'Sales (₹)', 'Commission (₹)', 'Usage Rate'];
    }
} catch(PDOException $e) {
    $rows = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            padding: 20px;
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
        
        .section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
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
        
        .progress-bar {
            background: #ecf0f1;
            border-radius: 10px;
            height: 8px;
            width: 100px;
            display: inline-block;
            vertical-align: middle;
        }
        .progress-bar .fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }
        .progress-bar .fill.green { background: #27ae60; }
        .progress-bar .fill.orange { background: #e67e22; }
        .progress-bar .fill.red { background: #e74c3c; }
        
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
        
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: stretch; }
            .header .filter-info { text-align: center; }
            table th, table td { padding: 8px 10px; font-size: 12px; }
            .section { padding: 15px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-list"></i> <?php echo $title; ?></h1>
            <div>
                <span class="filter-info">
                    <strong><?php echo getDateRangeDisplay($date_filter, $from_date, $to_date); ?></strong>
                    <?php if ($coupon_filter != 'all'): ?>
                        | Coupon: <strong><?php echo htmlspecialchars($coupon_filter); ?></strong>
                    <?php endif; ?>
                    | Executive: <strong><?php echo htmlspecialchars($executive_name); ?></strong>
                </span>
                <a href="salesexecutive_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title"><?php echo $title; ?></div>
            <div class="count-info">Showing <strong><?php echo count($rows); ?></strong> records</div>
            
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
                            <?php foreach ($rows as $row): 
                                $usage_rate = min($row['usage_rate'], 100);
                                $bar_color = $usage_rate > 70 ? 'green' : ($usage_rate > 40 ? 'orange' : 'red');
                            ?>
                            <tr>
                                <td><span class="coupon-code"><?php echo htmlspecialchars($row['coupon_code']); ?></span></td>
                                <td><?php echo $row['issued_on']; ?></td>
                                <td><?php echo $row['expiry_date']; ?></td>
                                <td><strong><?php echo $row['total_used']; ?></strong></td>
                                <td><strong><?php echo $row['total_students']; ?></strong></td>
                                <td><?php echo formatCurrency($row['sales_amount']); ?></td>
                                <td><?php echo formatCurrency($row['commission']); ?></td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="fill <?php echo $bar_color; ?>" style="width: <?php echo $usage_rate; ?>%;"></div>
                                    </div>
                                    <span style="margin-left: 8px; font-size: 12px; font-weight: 600;"><?php echo $usage_rate; ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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