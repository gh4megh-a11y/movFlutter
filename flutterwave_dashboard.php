<?php
/**
 * flutterwave_dashboard.php - ADMIN PAYMENT MANAGEMENT DASHBOARD
 * Place in: assets/admin/flutterwave_dashboard.php
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Check admin access
if (empty($_SESSION['iAdminID'])) {
    header('Location: login.php');
    exit;
}

include_once 'common.php';

$obj = $GLOBALS['obj'];

// Get statistics
$todayStats = $obj->MySQLSelect("SELECT 
    COUNT(*) as count,
    SUM(amount) as total
FROM flutterwave_transactions
WHERE eStatus = 'successful'
AND DATE(dCreatedAt) = CURDATE()");

$monthStats = $obj->MySQLSelect("SELECT 
    COUNT(*) as count,
    SUM(amount) as total
FROM flutterwave_transactions
WHERE eStatus = 'successful'
AND MONTH(dCreatedAt) = MONTH(NOW())
AND YEAR(dCreatedAt) = YEAR(NOW())");

$allTimeStats = $obj->MySQLSelect("SELECT 
    COUNT(*) as count,
    SUM(amount) as total
FROM flutterwave_transactions
WHERE eStatus = 'successful'");

$failedCount = $obj->MySQLSelect("SELECT COUNT(*) as count FROM flutterwave_transactions WHERE eStatus = 'failed'");
$refundedCount = $obj->MySQLSelect("SELECT COUNT(*) as count FROM flutterwave_transactions WHERE eStatus = 'refunded'");

// Get recent transactions
$recentTx = $obj->MySQLSelect("SELECT 
    t.*,
    u.vName as member_name
FROM flutterwave_transactions t
LEFT JOIN register_user u ON t.iMemberId = u.iUserId AND t.vMemberType = 'User'
ORDER BY t.dCreatedAt DESC
LIMIT 20");

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Dashboard - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { margin-bottom: 30px; }
        .header h1 { color: #333; margin-bottom: 10px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        .stat-card.success { border-left-color: #2ecc71; }
        .stat-card.danger { border-left-color: #e74c3c; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card h3 { color: #666; font-size: 14px; text-transform: uppercase; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #333; }
        .stat-card .subtext { color: #999; font-size: 12px; margin-top: 5px; }
        .table-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .section-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header h2 { color: #333; font-size: 18px; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #eee;
        }
        table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        table tr:hover {background: #f8f9fa; }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-successful { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-refunded { background: #d1ecf1; color: #0c5460; }
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 5px;
            text-decoration: none;
        }
        .btn-refund {
            background: #e74c3c;
            color: white;
        }
        .btn-refund:hover {
            background: #c0392b;
        }
        .btn-view {
            background: #3498db;
            color: white;
        }
        .btn-view:hover {
            background: #2980b9;
        }
        .filter-section {
            padding: 20px;
            background: white;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        input, select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn-apply {
            padding: 8px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-apply:hover { background: #5568d3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Payment Management Dashboard</h1>
            <p>Monitor all Flutterwave transactions</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card success">
                <h3>Today's Revenue</h3>
                <div class="value">GHS <?php echo number_format($todayStats[0]['total'] ?? 0, 2); ?></div>
                <div class="subtext"><?php echo ($todayStats[0]['count'] ?? 0); ?> transactions</div>
            </div>
            
            <div class="stat-card success">
                <h3>This Month Revenue</h3>
                <div class="value">GHS <?php echo number_format($monthStats[0]['total'] ?? 0, 2); ?></div>
                <div class="subtext"><?php echo ($monthStats[0]['count'] ?? 0); ?> transactions</div>
            </div>
            
            <div class="stat-card">
                <h3>All-Time Revenue</h3>
                <div class="value">GHS <?php echo number_format($allTimeStats[0]['total'] ?? 0, 2); ?></div>
                <div class="subtext"><?php echo ($allTimeStats[0]['count'] ?? 0); ?> transactions</div>
            </div>
            
            <div class="stat-card danger">
                <h3>Failed Transactions</h3>
                <div class="value"><?php echo ($failedCount[0]['count'] ?? 0); ?></div>
                <div class="subtext">Needs attention</div>
            </div>
            
            <div class="stat-card warning">
                <h3>Refunded</h3>
                <div class="value"><?php echo ($refundedCount[0]['count'] ?? 0); ?></div>
                <div class="subtext">Total refunds issued</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-section">
            <h3 style="margin-bottom: 15px;">Filters</h3>
            <div class="filter-row">
                <input type="text" placeholder="Member Name" id="filterName">
                <select id="filterStatus">
                    <option value="">All Statuses</option>
                    <option value="successful">Successful</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                    <option value="refunded">Refunded</option>
                </select>
                <input type="date" id="filterDate">
                <button class="btn-apply" onclick="applyFilters()">Apply Filters</button>
            </div>
        </div>
        
        <!-- Recent Transactions Table -->
        <div class="table-section">
            <div class="section-header">
                <h2>Recent Transactions</h2>
                <span id="recordCount" style="color: #999;">Loading...</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Member</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTx as $tx): ?>
                    <tr>
                        <td><code><?php echo substr($tx['tPaymentTransactionId'], 0, 20); ?>...</code></td>
                        <td><?php echo htmlspecialchars($tx['member_name'] ?? 'N/A'); ?></td>
                        <td><strong>GHS <?php echo number_format($tx['amount'], 2); ?></strong></td>
                        <td><span style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px; font-size: 12px;"><?php echo $tx['vPageType']; ?></span></td>
                        <td>
                            <span class="status-badge status-<?php echo $tx['eStatus']; ?>">
                                <?php echo ucfirst($tx['eStatus']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y H:i', strtotime($tx['dCreatedAt'])); ?></td>
                        <td>
                            <button class="action-btn btn-view" onclick="viewDetails('<?php echo $tx['tPaymentTransactionId']; ?>')">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <?php if ($tx['eStatus'] === 'successful'): ?>
                            <button class="action-btn btn-refund" onclick="refundTransaction('<?php echo $tx['tPaymentTransactionId']; ?>', <?php echo $tx['amount']; ?>)">
                                <i class="fas fa-undo"></i> Refund
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($recentTx)): ?>
            <div style="padding: 40px; text-align: center; color: #999;">
                <i class="fas fa-inbox" style="font-size: 48px; opacity: 0.3; margin-bottom: 20px; display: block;"></i>
                No transactions found
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function viewDetails(transactionId) {
            // Open transaction details modal
            alert('View details for: ' + transactionId);
        }
        
        function refundTransaction(transactionId, amount) {
            if (!confirm('Refund GHS ' + amount.toFixed(2) + '?')) return;
            
            fetch('flutterwave/refund_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_id: transactionId,
                    reason: 'Admin refund'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('✓ Refund successful!');
                    location.reload();
                } else {
                    alert('✗ Error: ' + data.message);
                }
            });
        }
        
        function applyFilters() {
            alert('Filters applied - implement filtering logic');
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>