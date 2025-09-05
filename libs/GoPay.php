<?php
// libs/GoPay.php
// Minimal GoPay client wrapper for creating payments and verifying notifications.
// This is a simplified implementation — consult GoPay API docs when moving to production.
class GoPay {
    private $cfg;
    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }
    // Create a payment session (simplified). Returns array with 'url' or throws Exception.
    public function createPaymentSession(array $orderData) : array {
        // In production: request OAuth, create payment via /payments/ endpoint, handle responses.
        // Here we simulate creation and return placeholder redirect URL (use config return_url with order_id).
        if (empty($this->cfg['return_url'])) throw new Exception('GoPay return_url not configured');
        $url = $this->cfg['return_url'].'?order_id='.urlencode($orderData['order_id']).'&amount='.urlencode($orderData['amount']);
        return ['url'=>$url];
    }
    // Verify incoming notification (stub). In real world validate signature/MAC.
    public function verifyNotification(array $payload, array $headers = []) : bool {
        // TODO: verify using client_secret, signature header or public key depending on GoPay integration.
        return true;
    }
}