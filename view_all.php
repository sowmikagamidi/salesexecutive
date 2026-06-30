<?php
// view_all.php
include_once 'config.php';

// Get parameters - only executives view
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$coupon_filter = isset($_GET['coupon_filter']) ? $_GET['coupon_filter'] : 'all';
$executive_filter = isset($_GET['executive_filter']) ? (int)$_GET['executive_filter'] : 0;
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

$date_condition = getDateCondition($date_filter, $from_date, $to_date);
$coupon_condition = $coupon_filter != 'all' ? "AND ph.coupon_id = " . (int)$coupon_filter : "";
$executive_condition = $executive_filter > 0 ? "AND ph.created_by = " . (int)$executive_filter : "";

$title = 'All Executives';
$headers = ['#', 'Executive Name', 'Total Orders', 'Sales (Order Amount)', 'Commission (25%)', 'Active Coupons'];
$rows = [];

try {
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
            $executive_condition
        LEFT JOIN tx_coupon_codes cc ON u.user_id = cc.created_by AND cc.is_active = 1 AND cc.coupon_code LIKE 'S%'
        WHERE u.user_type = 'SE'
        AND u.is_deleted = 0
        GROUP BY u.user_id
        HAVING total_sales > 0 OR active_coupons > 0
        ORDER BY total_sales DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            <h1><i class="fas fa-users"></i> <?php echo $title; ?></h1>
            <div>
                <span class="filter-info">
                    <strong><?php echo getDateRangeDisplay($date_filter, $from_date, $to_date); ?></strong>
                    <?php if ($coupon_filter != 'all'): ?>
                        | Coupon: <strong><?php echo htmlspecialchars($coupon_filter); ?></strong>
                    <?php endif; ?>
                    <?php if ($executive_filter > 0): ?>
                        | Executive: <strong><?php echo htmlspecialchars($executive_filter); ?></strong>
                    <?php endif; ?>
                </span>
                <a href="admin_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title"><?php echo $title; ?></div>
            <div class="count-info">Showing <strong><?php echo count($rows); ?></strong> executives</div>
            
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
                        <?php foreach ($rows as $index => $row): 
                            $rank = $index + 1;
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
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <p>No executives found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>