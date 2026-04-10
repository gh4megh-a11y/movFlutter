<?php
/**
 * FlutterwaveWebhook.php
 * Validates and parses incoming Flutterwave webhook events.
 *
 * Place in: assets/libraries/webview/flutterwave/FlutterwaveWebhook.php
 *
 * Usage in your webhook endpoint file:
 *
 *   require_once 'assets/libraries/webview/flutterwave/index.php';
 *
 *   $webhook = new FlutterwaveWebhook();
 *   $event   = $webhook->handle();          // returns parsed event or dies with 401/400
 *
 *   switch ($event['event']) {
 *       case 'charge.completed':
 *           // update your order …
 *           break;
 *       case 'transfer.completed':
 *           // settle provider wallet …
 *           break;
 *   }
 *   http_response_code(200);
 *   echo json_encode(['status' => 'ok']);
 */

require_once __DIR__ . '/FlutterwaveConfig.php';
require_once __DIR__ . '/FlutterwaveLogger.php';

class FlutterwaveWebhook {

    private FlutterwaveConfig $cfg;
    private FlutterwaveLogger $log;

    public function __construct() {
        $this->cfg = FlutterwaveConfig::getInstance();
        $this->log = new FlutterwaveLogger($this->cfg);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Validate signature, decode body, and return the parsed event array.
     * Terminates with an HTTP error response on failure.
     *
     * @return array Parsed webhook payload
     */
    public function handle(): array {
        $rawBody   = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';

        if (!$this->verifySignature($signature)) {
            $this->log->error('webhook_invalid_signature', ['sig' => substr($signature, 0, 8) . '…']);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid webhook signature']);
            exit;
        }

        $payload = json_decode($rawBody, true);
        if (empty($payload) || !isset($payload['event'])) {
            $this->log->error('webhook_bad_payload', []);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit;
        }

        $this->log->info('webhook_received', [
            'event'  => $payload['event'],
            'tx_ref' => $payload['data']['tx_ref'] ?? 'n/a',
        ]);

        return $payload;
    }

    /**
     * Extract standardised transaction data from a webhook event.
     *
     * @param array $event  Result of handle()
     * @return array
     */
    public function extractTransactionData(array $event): array {
        $data = $event['data'] ?? [];
        return [
            'event'                 => $event['event']              ?? '',
            'tPaymentTransactionId' => $data['id']                  ?? '',
            'tx_ref'                => $data['tx_ref']              ?? '',
            'flw_ref'               => $data['flw_ref']             ?? '',
            'amount'                => $data['amount']              ?? 0,
            'currency'              => $data['currency']            ?? '',
            'status'                => $data['status']              ?? '',
            'payment_type'          => $data['payment_type']        ?? '',
            'customer_email'        => $data['customer']['email']   ?? '',
            'customer_name'         => $data['customer']['name']    ?? '',
            'card_brand'            => $data['card']['brand']       ?? '',
            'card_last4'            => $data['card']['last_4digits']?? '',
            'meta'                  => $data['meta']                ?? [],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Compare the incoming hash against the webhook secret stored in the DB.
     * Flutterwave sends the secret hash as the `verif-hash` header.
     */
    private function verifySignature(string $incomingHash): bool {
        $secret = $this->cfg->webhookSecret;
        if (empty($secret)) {
            // If no secret configured, skip validation (not recommended for production)
            $this->log->error('webhook_no_secret_configured', []);
            return false;
        }
        return hash_equals($secret, $incomingHash);
    }
}
