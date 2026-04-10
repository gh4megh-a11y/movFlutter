<?php
/**
 * flutterwave_payment_modal.php - FLUTTERWAVE JAVASCRIPT MODAL PAYMENT
 * Place in: assets/libraries/webview/flutterwave/flutterwave_payment_modal.php
 * 
 * This uses Flutterwave's official JavaScript modal checkout
 * Works with all merchant accounts - no server-side encryption needed
 * Access: https://apiservice.movvack.com/assets/libraries/webview/flutterwave/flutterwave_payment_modal.php
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: text/html; charset=utf-8');

$log_file = __DIR__ . '/payment_errors.log';

try {
    // Include common
    $base_path = __DIR__;
    $public_html = dirname(dirname(dirname(dirname($base_path))));
    $common_file = $public_html . '/common.php';
    
    if (!file_exists($common_file)) {
        throw new Exception('common.php not found');
    }
    
    include_once $common_file;
    
    if (!isset($GLOBALS['obj'])) {
        throw new Exception('Database object not found');
    }
    
    $obj = $GLOBALS['obj'];
    
    file_put_contents($log_file, "\n" . str_repeat("=", 80) . "\n", FILE_APPEND);
    file_put_contents($log_file, "FLUTTERWAVE MODAL - " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    file_put_contents($log_file, "REQUEST: " . json_encode($_REQUEST) . "\n", FILE_APPEND);
    
    // Get parameters
    $PAGE_TYPE = $_REQUEST['PAGE_TYPE'] ?? 'WALLET_MONEY_ADD';
    $AMOUNT = isset($_REQUEST['AMOUNT']) ? (float)$_REQUEST['AMOUNT'] : 0;
    $tSessionId = $_REQUEST['tSessionId'] ?? '';
    $GeneralMemberId = $_REQUEST['GeneralMemberId'] ?? '';
    $GeneralUserType = $_REQUEST['GeneralUserType'] ?? '';
    $iServiceId = $_REQUEST['iServiceId'] ?? '';
    $iTripId = $_REQUEST['iTripId'] ?? '0';
    $iOrderId = $_REQUEST['iOrderId'] ?? '';
    
    file_put_contents($log_file, "Parameters: PAGE_TYPE=$PAGE_TYPE, AMOUNT=$AMOUNT, SESSION=$tSessionId\n", FILE_APPEND);
    
    // Validate required parameters
    if (empty($tSessionId) || empty($GeneralMemberId) || empty($GeneralUserType) || $AMOUNT <= 0) {
        throw new Exception('Missing or invalid parameters');
    }
    
    file_put_contents($log_file, "Validation: PASS\n", FILE_APPEND);
    
    // Get user data
    if ($GeneralUserType == "Hotel" || $GeneralUserType == "Kiosk") {
        $userData = get_value("hotel", "iHotelId as iMemberId, tSessionId, vName, vEmail, vCountry, vCurrencyPassenger as userCurrency", "iHotelId", $GeneralMemberId);
    } elseif ($GeneralUserType == "Driver") {
        $userData = get_value("register_driver", "iDriverId as iMemberId, tSessionId, vName, vEmail, vCountry, vCurrencyDriver as userCurrency", "iDriverId", $GeneralMemberId);
    } else {
        $userData = get_value("register_user", "iUserId as iMemberId, tSessionId, vName, vEmail, vCountry, vCurrencyPassenger as userCurrency", "iUserId", $GeneralMemberId);
    }
    
    if (empty($userData)) {
        file_put_contents($log_file, "User not found\n", FILE_APPEND);
        throw new Exception('User not found');
    }
    
    file_put_contents($log_file, "User found: " . $userData[0]['vName'] . "\n", FILE_APPEND);
    
    // Validate session
    if ($userData[0]['tSessionId'] !== $tSessionId) {
        file_put_contents($log_file, "Session mismatch\n", FILE_APPEND);
        throw new Exception('Session mismatch');
    }
    
    file_put_contents($log_file, "Session valid\n", FILE_APPEND);
    
    // Get Flutterwave keys
    $pubResult = $obj->MySQLSelect("SELECT vValue FROM configurations_payment WHERE vName = 'FLUTTERWAVE_PUBLIC_KEY_LIVE' LIMIT 1");
    $publicKey = !empty($pubResult) ? $pubResult[0]['vValue'] : '';
    
    if (empty($publicKey)) {
        throw new Exception('Flutterwave public key not configured');
    }
    
    file_put_contents($log_file, "Public key loaded: " . substr($publicKey, 0, 20) . "...\n", FILE_APPEND);
    
    // Get currency conversion
    $userCurrencyData = $obj->MySQLSelect("SELECT Ratio FROM currency WHERE vName = '" . addslashes($userData[0]['userCurrency']) . "' LIMIT 1");
    $userCurrencyRatio = !empty($userCurrencyData) ? (float)$userCurrencyData[0]['Ratio'] : 1;
    
    $defaultCurrencyData = get_value('currency', 'vName, Ratio', 'eDefault', 'Yes');
    $defaultCurrency = $defaultCurrencyData[0]['vName'] ?? 'USD';
    $defaultCurrencyRatio = (float)($defaultCurrencyData[0]['Ratio'] ?? 1);
    
    // Convert user amount to default currency
    $finalAmount = round(($AMOUNT / $userCurrencyRatio) * $defaultCurrencyRatio, 2);
    
    file_put_contents($log_file, "Currency conversion: $AMOUNT {$userData[0]['userCurrency']} -> $finalAmount $defaultCurrency\n", FILE_APPEND);
    
    // Generate unique transaction reference
    $txRef = 'tx-' . time() . '-' . mt_rand(10000, 99999);
    
    file_put_contents($log_file, "Transaction reference: $txRef\n", FILE_APPEND);
    
    ob_end_clean();
    
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Flutterwave Payment</title>
    <link href="https://fonts.googleapis.com/css?family=Poppins:100,400,500,600,700,800,900&display=swap" rel="stylesheet"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            animation: slideUp 0.5s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            color: #666;
            font-size: 14px;
        }
        .payment-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #666;
            font-weight: 500;
            font-size: 14px;
        }
        .detail-value {
            color: #333;
            font-weight: 600;
            font-size: 16px;
        }
        .amount-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 30px;
        }
        .amount-section .amount-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .amount-section .amount-value {
            font-size: 32px;
            font-weight: bold;
        }
        .button-group {
            display: flex;
            gap: 10px;
        }
        button {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        .btn-pay {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-pay:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .btn-pay:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-cancel {
            background: #e0e0e0;
            color: #333;
        }
        .btn-cancel:hover {
            background: #d0d0d0;
        }
        .spinner {
            display: none;
            text-align: center;
            margin-top: 20px;
        }
        .spinner.show {
            display: block;
        }
        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }
        .error.show {
            display: block;
        }
        .info-text {
            color: #666;
            font-size: 12px;
            text-align: center;
            margin-top: 15px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Payment</h1>
            <p>Secure payment powered by Flutterwave</p>
        </div>
        
        <div class="error" id="errorMsg"></div>
        
        <div class="payment-details">
            <div class="detail-row">
                <span class="detail-label">Customer Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($userData[0]['vName']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Email</span>
                <span class="detail-value"><?php echo htmlspecialchars($userData[0]['vEmail']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment Type</span>
                <span class="detail-value"><?php echo htmlspecialchars($PAGE_TYPE); ?></span>
            </div>
        </div>
        
        <div class="amount-section">
            <div class="amount-label">Total Amount</div>
            <div class="amount-value"><?php echo number_format($finalAmount, 2); ?> <?php echo $defaultCurrency; ?></div>
        </div>
        
        <div class="button-group">
            <button class="btn-pay" id="payBtn" onclick="makePayment()">Pay Now</button>
            <button class="btn-cancel" onclick="cancelPayment()">Cancel</button>
        </div>
        
        <div class="spinner" id="spinner">
            <div class="loader"></div>
            <p>Processing payment...</p>
        </div>
        
        <div class="info-text">
            <p>✓ Your payment is secured with industry-leading encryption<br>
            ✓ Multiple payment methods available<br>
            ✓ Fast and reliable transactions</p>
        </div>
    </div>
    
    <!-- Flutterwave JavaScript Library -->
    <script src="https://checkout.flutterwave.com/v3.js"></script>
    
    <script>
        const publicKey = "<?php echo $publicKey; ?>";
        const txRef = "<?php echo $txRef; ?>";
        const amount = <?php echo $finalAmount; ?>;
        const currency = "<?php echo $defaultCurrency; ?>";
        const customerName = "<?php echo htmlspecialchars($userData[0]['vName']); ?>";
        const customerEmail = "<?php echo htmlspecialchars($userData[0]['vEmail']); ?>";
        const memberID = "<?php echo $GeneralMemberId; ?>";
        const userType = "<?php echo $GeneralUserType; ?>";
        const pageType = "<?php echo $PAGE_TYPE; ?>";
        const sessionID = "<?php echo $tSessionId; ?>";
        const tripID = "<?php echo $iTripId; ?>";
        const orderID = "<?php echo $iOrderId; ?>";
        
        function makePayment() {
            document.getElementById('payBtn').disabled = true;
            document.getElementById('spinner').classList.add('show');
            
            FlutterwaveCheckout({
                public_key: publicKey,
                tx_ref: txRef,
                amount: amount,
                currency: currency,
                payment_options: "card, ussd, mobilemoneyghana, account, qr",
                customer: {
                    email: customerEmail,
                    name: customerName
                },
                customizations: {
                    title: "Movvack Payment",
                    description: pageType,
                    logo: "https://movvack.com/logo.png"
                },
                meta: {
                    iMemberId: memberID,
                    UserType: userType,
                    PAGE_TYPE: pageType,
                    iTripId: tripID,
                    iOrderId: orderID
                },
                callback: function(data) {
                    console.log("Payment callback:", data);
                    verifyPayment(data.transaction_id);
                },
                onclose: function() {
                    console.log("Modal closed");
                    document.getElementById('payBtn').disabled = false;
                    document.getElementById('spinner').classList.remove('show');
                    showError("Payment cancelled");
                }
            });
        }
        
        function verifyPayment(transactionId) {
            fetch("<?php echo $tconfig['tsite_url']; ?>assets/libraries/webview/flutterwave/payment_callback.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    transaction_id: transactionId,
                    tx_ref: txRef,
                    tSessionId: sessionID,
                    GeneralMemberId: memberID,
                    GeneralUserType: userType,
                    PAGE_TYPE: pageType
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    window.location.href = "<?php echo $tconfig['tsite_url']; ?>assets/libraries/webview/success.php?success=1&message=Payment+Successful&tSessionId=" + sessionID;
                } else {
                    showError(data.message || "Payment verification failed");
                    document.getElementById('payBtn').disabled = false;
                    document.getElementById('spinner').classList.remove('show');
                }
            })
            .catch(err => {
                console.error("Verification error:", err);
                showError("Error verifying payment: " + err.message);
                document.getElementById('payBtn').disabled = false;
                document.getElementById('spinner').classList.remove('show');
            });
        }
        
        function cancelPayment() {
            if (confirm("Cancel payment?")) {
                window.location.href = "<?php echo $tconfig['tsite_url']; ?>assets/libraries/webview/failure.php?success=0&message=Payment+Cancelled&tSessionId=" + sessionID;
            }
        }
        
        function showError(message) {
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.textContent = message;
            errorDiv.classList.add('show');
        }
    </script>
</body>
</html>
<?php

} catch (Exception $e) {
    file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Error</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f5f5f5; margin: 0; }
        .error-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; text-align: center; }
        h1 { color: #c33; margin-bottom: 20px; }
        p { color: #666; margin-bottom: 20px; line-height: 1.6; }
        a { color: #667eea; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>❌ Payment Error</h1>
        <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
        <a href="javascript:history.back()">Go Back</a>
    </div>
</body>
</html>
<?php
}
?>