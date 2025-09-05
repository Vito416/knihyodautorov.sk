<?php
// libs/GoPayAdvanced.php
// More complete GoPay client skeleton using cURL. Replace endpoints and signing as required by GoPay docs.
class GoPayAdvanced {
    private $cfg;
    private $apiBase;
    public function __construct(array $cfg, string $apiBase = 'https://gw.gopay.com/api') {
        $this->cfg = $cfg;
        $this->apiBase = rtrim($apiBase, '/');
    }

    private function curl($path, $method = 'GET', $body = null, $headers = []) {
        $url = $this->apiBase . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: '.strlen($payload);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) throw new Exception('cURL error: '.$err);
        $decoded = json_decode($res, true);
        return ['http_info' => $info, 'raw' => $res, 'json' => $decoded];
    }

    // Example: create payment (needs OAuth in prod)
    public function createPayment(array $paymentData) : array {
        // In production you must obtain OAuth token first and include Authorization header.
        // We'll call a placeholder endpoint or simulate the response.
        if (empty($this->cfg['return_url'])) throw new Exception('GoPay return_url not configured');
        // Minimal local simulation: return redirect url
        $redirect = $this->cfg['return_url'] . '?order_id='.urlencode($paymentData['order_id']).'&amount='.urlencode($paymentData['amount']);
        return ['redirect_url' => $redirect, 'raw' => null];
    }

    // Verify webhook signature using configured secret (stub)
    public function verifyWebhook(array $payload, array $headers = []) : bool {
        // Real implementation: compute HMAC or verify RSA signature based on GoPay doc.
        if (!empty($this->cfg['client_secret'])) {
            // Example: if GoPay sent X-Signature: HMAC_SHA256(payload, client_secret)
            $sigHeader = $headers['X-Signature'] ?? $headers['x-signature'] ?? '';
            if ($sigHeader) {
                $calc = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $this->cfg['client_secret']);
                return hash_equals($calc, $sigHeader);
            }
        }
        // fallback allow (not secure)
        return true;
    }
}