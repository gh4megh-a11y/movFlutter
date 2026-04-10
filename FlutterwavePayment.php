<?php
/**
 * FlutterwavePayment.php - ALTERNATIVE: USE STANDARD V3 API (NO ENCRYPTION)
 * Place in: assets/libraries/webview/flutterwave/FlutterwavePayment.php
 */

require_once __DIR__ . '/FlutterwaveConfig.php';
require_once __DIR__ . '/FlutterwaveLogger.php';

class FlutterwavePayment {

    private $cfg;
    private $log;

    public function __construct() {
        $this->cfg = FlutterwaveConfig::getInstance();
        $this->log = new FlutterwaveLogger($this->cfg);
    }

    public static function getInstance() {
        return new self();
    }

    /**
     * Execute payment using Standard V3 API (no encryption)
     * This works even if merchant is not enabled for encrypted charges
     */
    public function execute(array $paymentData) {
        // Validate gateway is active
        if (!$this->cfg->isActive()) {
            return $this->fail('Flutterwave payment gateway is not active or not fully configured.');
        }

        // Validate amount
        $amount = round((float)($paymentData['amount'] ?? 0), 2);
        $currency = strtoupper(trim($paymentData['vCurrency'] ?? $this->cfg->defaultCurrency));

        $limitErr = $this->validateAmount($amount);
        if ($limitErr) {
            return $this->fail($limitErr);
        }

        // Build metadata
        $meta = [];
        if (!empty($paymentData['iMemberId'])) {
            $meta['iMemberId'] = $paymentData['iMemberId'];
        }
        if (!empty($paymentData['UserType'])) {
            $meta['UserType'] = $paymentData['UserType'];
        }
        if (!empty($paymentData['iOrderId'])) {
            $meta['iOrderId'] = $paymentData['iOrderId'];
        }
        if (!empty($paymentData['iTripId'])) {
            $meta['iTripId'] = $paymentData['iTripId'];
        }
        if (!empty($paymentData['PAGE_TYPE'])) {
            $meta['PAGE_TYPE'] = $paymentData['PAGE_TYPE'];
        }

        $txRef = 'tx-' . time() . '-' . mt_rand(1000, 9999);
        $description = $paymentData['description'] ?? 'Payment';

        // Build payload (UNENCRYPTED - Standard V3 API)
        $payload = [
            'tx_ref' => $txRef,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'narration' => $description,
            'meta' => $meta,
            'customer' => $this->buildCustomer($paymentData),
            'payment_options' => 'card,ussd,mobilemoneyghana,account',
            'redirect_url' => 'https://movvack.com/payment-callback',
            'customizations' => [
                'title' => 'Movvack Payment',
                'description' => $description,
                'logo' => 'https://movvack.com/logo.png'
            ]
        ];

        // Log request
        $this->log->info('execute', [
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'method' => 'standard_v3_unencrypted'
        ]);

        // ====================================================================
        // SEND UNENCRYPTED PAYLOAD TO V3 API
        // ====================================================================
        $endpoint = 'https://api.flutterwave.com/v3/charges?type=card';
        $resp = $this->requestStandard($endpoint, $payload);
        
        return $this->handleChargeResponse($resp, $paymentData);
    }

    /**
     * Verify a transaction
     */
    public function verifyTransaction($transactionId) {
        if (empty($transactionId)) {
            return $this->fail('transactionId is required.');
        }

        $url = "https://api.flutterwave.com/v3/transactions/{$transactionId}/verify";
        $resp = $this->request('GET', $url);

        if ($resp['http_code'] >= 200 && $resp['http_code'] < 300 && ($resp['body']['status'] ?? '') === 'success') {
            $data = $resp['body']['data'] ?? [];
            $status = $data['status'] ?? 'unknown';

            if (in_array($status, ['successful', 'completed'], true)) {
                return [
                    'Action' => '1',
                    'tPaymentTransactionId' => $data['id'] ?? $transactionId,
                    'amount' => $data['amount'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'status' => $status,
                    'message' => 'success',
                    'USER_APP_PAYMENT_METHOD' => 'Flutterwave',
                ];
            }

            return $this->fail($data['processor_response'] ?? 'Verification failed');
        }

        return $this->fail($resp['body']['message'] ?? 'Verification API error');
    }

    /**
     * Refund a payment
     */
    public function refundPayment(array $paymentData) {
        if (!$this->cfg->isActive()) {
            return $this->fail('Flutterwave payment gateway is not active.');
        }

        if (!$this->cfg->autoRefundEnabled) {
            return $this->fail('Auto-refund is disabled in payment configuration.');
        }

        $txId = $paymentData['tPaymentTransactionId'] ?? '';
        if (empty($txId)) {
            return $this->fail('tPaymentTransactionId is required.');
        }

        $body = ['id' => $txId];
        if (!empty($paymentData['amount'])) {
            $body['amount'] = number_format(round((float)$paymentData['amount'], 2), 2, '.', '');
        }

        $url = "https://api.flutterwave.com/v3/transactions/{$txId}/refund";
        $resp = $this->request('POST', $url, $body);

        $this->log->info('refund', ['txId' => $txId]);
        return $this->handleSimpleResponse($resp, $txId);
    }

    /**
     * Capture an authorized payment
     */
    public function capturePayment(array $paymentData) {
        if (!$this->cfg->isActive()) {
            return $this->fail('Flutterwave payment gateway is not active.');
        }

        $chargeId = $paymentData['iAuthorizePaymentId'] ?? '';
        if (empty($chargeId)) {
            return $this->fail('iAuthorizePaymentId is required.');
        }

        $body = [];
        if (!empty($paymentData['amount'])) {
            $body['amount'] = number_format(round((float)$paymentData['amount'], 2), 2, '.', '');
        }

        $url = "https://api.flutterwave.com/v3/charges/{$chargeId}/capture";
        $resp = $this->request('POST', $url, $body);

        $this->log->info('capture', ['chargeId' => $chargeId]);
        return $this->handleSimpleResponse($resp, $chargeId);
    }

    /**
     * Void an authorized payment
     */
    public function cancelAuthorizedPayment(array $paymentData) {
        if (!$this->cfg->isActive()) {
            return $this->fail('Flutterwave payment gateway is not active.');
        }

        $chargeId = $paymentData['iAuthorizePaymentId'] ?? '';
        if (empty($chargeId)) {
            return $this->fail('iAuthorizePaymentId is required.');
        }

        $url = "https://api.flutterwave.com/v3/charges/{$chargeId}/void";
        $resp = $this->request('POST', $url, []);

        $this->log->info('void', ['chargeId' => $chargeId]);
        return $this->handleSimpleResponse($resp, $chargeId);
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    /**
     * Make standard unencrypted request to Flutterwave V3 API
     */
    private function requestStandard($url, $payload) {
        $attempts = max(1, $this->cfg->retryAttempts);
        $timeout = max(5, $this->cfg->apiTimeout);
        $lastResult = ['http_code' => 0, 'body' => [], 'error' => ''];

        for ($i = 0; $i < $attempts; $i++) {
            file_put_contents(__DIR__ . '/payment_errors.log', "Standard API attempt " . ($i + 1) . " to: $url\n", FILE_APPEND);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->cfg->secretKey,
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            file_put_contents(__DIR__ . '/payment_errors.log', "HTTP Code: $httpCode\n", FILE_APPEND);
            file_put_contents(__DIR__ . '/payment_errors.log', "Response: $raw\n", FILE_APPEND);

            if ($raw === false) {
                file_put_contents(__DIR__ . '/payment_errors.log', "cURL error: $curlErr\n", FILE_APPEND);
                $lastResult = ['http_code' => 0, 'body' => [], 'error' => $curlErr];
                usleep(500000);
                continue;
            }

            $decoded = json_decode($raw, true) ?? [];
            $lastResult = ['http_code' => $httpCode, 'body' => $decoded, 'error' => ''];

            // Only retry on server errors
            if ($httpCode < 500) break;

            usleep(500000);
        }

        return $lastResult;
    }

    private function buildCustomer(array $pd) {
        $customer = [];
        if (!empty($pd['customer_email'])) {
            $customer['email'] = $pd['customer_email'];
        }
        if (!empty($pd['customer_name'])) {
            $customer['name'] = $pd['customer_name'];
        }
        if (!empty($pd['customer_phone'])) {
            $customer['phonenumber'] = $pd['customer_phone'];
        }
        return $customer;
    }

    private function validateAmount($amount) {
        if ($amount <= 0) {
            return 'Payment amount must be greater than zero.';
        }
        if ($amount < $this->cfg->minTransactionAmount) {
            return "Amount is below the minimum allowed ({$this->cfg->minTransactionAmount}).";
        }
        if ($amount > $this->cfg->maxTransactionAmount) {
            return "Amount exceeds the maximum allowed ({$this->cfg->maxTransactionAmount}).";
        }
        return null;
    }

    private function handleChargeResponse(array $resp, array $pd) {
        $http = $resp['http_code'];
        $body = $resp['body'];

        if ($http >= 200 && $http < 300 && ($body['status'] ?? '') === 'success') {
            $data = $body['data'] ?? [];
            $status = $data['status'] ?? null;

            // Check for 3DS redirect
            if (!empty($data['auth_url'])) {
                return [
                    'Action' => '1',
                    'AUTHENTICATION_REQUIRED' => 'Yes',
                    'AUTHENTICATION_URL' => $data['auth_url'],
                ];
            }

            // Charge succeeded
            if (in_array($status, ['successful', 'authorized', 'pending'], true)) {
                $result = [
                    'Action' => '1',
                    'tPaymentTransactionId' => $data['id'] ?? ($data['flw_ref'] ?? null),
                    'message' => 'success',
                    'USER_APP_PAYMENT_METHOD' => 'Flutterwave',
                    'tx_ref' => $data['tx_ref'] ?? null,
                    'flw_ref' => $data['flw_ref'] ?? null,
                ];

                // Extract card details
                $cardInfo = $data['card'] ?? ($data['authorization'] ?? []);
                if (!empty($cardInfo)) {
                    $result['vCardBrand'] = $cardInfo['brand'] ?? ($cardInfo['card_type'] ?? null);
                    $result['last4digits'] = $cardInfo['last_4digits'] ?? ($cardInfo['last4'] ?? null);
                }

                return $result;
            }

            // Charge failed
            $msg = $data['processor_response'] ?? ($body['message'] ?? 'Payment failed');
            return $this->fail($msg, $status ?? 'failed');
        }

        $errMsg = $body['message'] ?? 'API request failed';
        return $this->fail($errMsg);
    }

    private function handleSimpleResponse(array $resp, $fallbackId) {
        $http = $resp['http_code'];
        $body = $resp['body'];

        if ($http >= 200 && $http < 300 && ($body['status'] ?? '') === 'success') {
            $data = $body['data'] ?? [];
            return [
                'Action' => '1',
                'tPaymentTransactionId' => $data['id'] ?? ($data['flw_ref'] ?? $fallbackId),
                'message' => 'success',
                'USER_APP_PAYMENT_METHOD' => 'Flutterwave',
            ];
        }

        return $this->fail($body['message'] ?? 'Operation failed');
    }

    private function request($method, $url, $body = []) {
        $attempts = max(1, $this->cfg->retryAttempts);
        $timeout = max(5, $this->cfg->apiTimeout);
        $lastResult = ['http_code' => 0, 'body' => [], 'error' => ''];

        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->cfg->secretKey,
                ],
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
                    array_filter($body, function($v) { return $v !== null && $v !== ''; })
                ));
            }

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                $lastResult = ['http_code' => 0, 'body' => [], 'error' => $curlErr];
                usleep(500000);
                continue;
            }

            $decoded = json_decode($raw, true) ?? [];
            $lastResult = ['http_code' => $httpCode, 'body' => $decoded, 'error' => ''];

            // Only retry on server errors
            if ($httpCode < 500) break;

            usleep(500000);
        }

        return $lastResult;
    }

    private function fail($message, $status = 'failed') {
        return [
            'Action' => '0',
            'status' => $status,
            'message' => $message,
        ];
    }
}
?>