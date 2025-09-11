<?php
declare(strict_types=1);

/**
 * GoPayAdvanced — lightweight client for limited hosting (no OpenSSL, no getenv).
 * - Uses only cURL + hash_hmac.
 * - Webhook verification: HMAC-SHA256 with client_secret.
 * - No fallback to "true" — missing/invalid signature => reject.
 */
final class GoPayAdvanced
{
    private array $config;
    private string $apiBase;

    public function __construct(array $config, string $apiBase = 'https://gw.gopay.com/api')
    {
        $this->config = $config;
        $this->apiBase = rtrim($apiBase, '/');
    }

    private function curl(string $path, string $method = 'GET', $body = null, array $headers = []): array
    {
        $url = $this->apiBase . $path;
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: '.strlen($payload);
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            throw new \RuntimeException('cURL error: ' . $err);
        }

        $decoded = json_decode($res, true);
        return ['http_info' => $info, 'raw' => $res, 'json' => $decoded];
    }

    /**
     * Stub pro vytvoření platby — reálná integrace vyžaduje OAuth.
     */
    public function createPayment(array $paymentData): array
    {
        if (empty($this->config['return_url'])) {
            throw new \RuntimeException('GoPay return_url not configured');
        }

        $redirect = $this->config['return_url']
            . '?order_id=' . urlencode((string)($paymentData['order_id'] ?? ''))
            . '&amount=' . urlencode((string)($paymentData['amount'] ?? ''));

        return ['redirect_url' => $redirect, 'raw' => null];
    }

    /**
     * Verify webhook using HMAC-SHA256 and client_secret.
     * @return bool true if signature matches, false otherwise
     */
    public function verifyWebhook(array $payload, array $headers = [], ?int $maxAgeSeconds = 300): bool
    {
        if (empty($this->config['client_secret'])) {
            return false;
        }

        // normalize headers
        $lcHeaders = [];
        foreach ($headers as $k => $v) {
            $lcHeaders[strtolower($k)] = $v;
        }

        $sig = $lcHeaders['x-signature'] ?? '';
        if ($sig === '') {
            return false;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return false;
        }

        // optional replay protection
        if ($maxAgeSeconds !== null && isset($lcHeaders['x-timestamp'])) {
            $ts = (int)$lcHeaders['x-timestamp'];
            if ($ts <= 0) return false;
            if (abs(time() - $ts) > $maxAgeSeconds) {
                return false;
            }
        }

        $calc = hash_hmac('sha256', $body, (string)$this->config['client_secret']);
        $ok = hash_equals($calc, $sig);

        if (function_exists('sodium_memzero')) {
            sodium_memzero($calc);
        }

        return $ok;
    }
}