<?php
declare(strict_types=1);

/**
 * GoPay SDK wrapper using the project's Logger (Logger::info/warn/systemError/systemMessage).
 *
 * - No PSR-3 dependency; uses project's Logger static class.
 * - Uses FileCache for OAuth token caching.
 * - Falls back to direct HTTP if official SDK isn't available or fails.
 * - Sanitizes payloads before logging.
 */

final class GoPaySdkWrapper implements PaymentGatewayInterface
{
    private array $cfg;
    private ?object $client = null;
    private FileCache $cache;
    private string $cacheKey = 'gopay_oauth_token';

    public function __construct(array $cfg, FileCache $cache)
    {
        $this->cfg = $cfg;
        $this->cache = $cache;

        // basic config validation
        $required = ['gatewayUrl', 'clientId', 'clientSecret', 'goid', 'scope'];
        $missing = [];
        foreach ($required as $k) {
            if (empty($this->cfg[$k])) {
                $missing[] = $k;
            }
        }
        if (!empty($missing)) {
            throw new \InvalidArgumentException('GoPay config missing keys: ' . implode(',', $missing));
        }

        // try init official SDK if available (non-fatal)
        if (class_exists('\GoPay') && function_exists('\GoPay\payments')) {
            try {
                $this->client = \GoPay\payments([
                    'goid' => $this->cfg['goid'],
                    'clientId' => $this->cfg['clientId'],
                    'clientSecret' => $this->cfg['clientSecret'],
                    'gatewayUrl' => $this->cfg['gatewayUrl'],
                    'language' => $this->cfg['language'] ?? 'EN',
                    'scope' => $this->cfg['scope'],
                ]);
            } catch (\Throwable $e) {
                $this->client = null;
                try { Logger::warn('GoPay SDK init failed, falling back to HTTP', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            }
        } else {
            $this->client = null;
        }
    }

    /**
     * Get OAuth token (cached).
     *
     * @return string
     * @throws \RuntimeException
     */
    public function getToken(): string
    {
        // quick cache check (fast path)
        $tokenData = $this->cache->get($this->cacheKey);
        if (is_array($tokenData) && isset($tokenData['token'], $tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
            return (string)$tokenData['token'];
        }

        // prevent token stampede with a simple file lock (per-process)
        $lockFile = sys_get_temp_dir() . '/gopay_token_lock_' . md5($this->cacheKey);
        $fp = @fopen($lockFile, 'c+');
        $locked = false;
        if ($fp) {
            $locked = flock($fp, LOCK_EX);
        }

        try {
            // re-check cache inside lock (double-checked locking)
            $tokenData = $this->cache->get($this->cacheKey);
            if (is_array($tokenData) && isset($tokenData['token'], $tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
                return (string)$tokenData['token'];
            }

            $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/oauth2/token';
            $credentials = base64_encode($this->cfg['clientId'] . ':' . $this->cfg['clientSecret']);
            $headers = [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: MyShop/GoPaySdkWrapper/1.0'
            ];
            $body = http_build_query(['grant_type' => 'client_credentials', 'scope' => $this->cfg['scope']]);

            $attempts = 3;
            $backoffMs = 200;
            $lastEx = null;
            for ($i = 0; $i < $attempts; $i++) {
                try {
                    $resp = $this->doRequest('POST', $url, $headers, $body, ['raw' => true, 'expect_json' => true]);
                    $httpCode = $resp['http_code'];
                    $decoded = $resp['json'];
                    $raw = $resp['body'];

                    if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded['access_token'], $decoded['expires_in'])) {
                        $this->cache->set($this->cacheKey, [
                            'token' => (string)$decoded['access_token'],
                            'expires_at' => time() + (int)$decoded['expires_in'] - 10,
                        ], (int)$decoded['expires_in']);

                        return (string)$decoded['access_token'];
                    }

                    $msg = is_array($decoded) ? ($decoded['error_description'] ?? json_encode($decoded)) : $raw;
                    throw new \RuntimeException("GoPay token endpoint returned HTTP {$httpCode}: {$msg}");
                } catch (\Throwable $e) {
                    $lastEx = $e;
                    try { Logger::warn('getToken attempt failed', null, ['attempt' => $i + 1, 'exception' => (string)$e]); } catch (\Throwable $_) {}
                    usleep($backoffMs * 1000);
                    $backoffMs *= 2;
                }
            }

            $ex = $lastEx ?? new \RuntimeException('Unknown error obtaining token');
            try { Logger::systemError($ex, null, null, ['phase' => 'getToken']); } catch (\Throwable $_) {}
            throw new \RuntimeException('Failed to obtain GoPay OAuth token: ' . $ex->getMessage());
        } finally {
            if ($locked && isset($fp) && is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            } elseif (isset($fp) && is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * Create payment, return assoc array (decoded JSON).
     *
     * @param array $payload
     * @return array
     * @throws \RuntimeException
     */
    public function createPayment(array $payload): array
    {
        // ensure target.type and goid present (goid as string to avoid 32bit overflow)
        $goidVal = (string)$this->cfg['goid'];
        if (empty($payload['target']) && $goidVal !== '') {
            $payload['target'] = [
                'type' => 'ACCOUNT',
                'goid' => $goidVal,
            ];
        } else {
            if (!isset($payload['target']['type'])) {
                $payload['target']['type'] = 'ACCOUNT';
            }
            if (empty($payload['target']['goid']) && $goidVal !== '') {
                $payload['target']['goid'] = $goidVal;
            } else {
                // ensure it's string
                $payload['target']['goid'] = (string)$payload['target']['goid'];
            }
        }

        // prefer SDK client
        if ($this->client !== null && method_exists($this->client, 'createPayment')) {
            try {
                $resp = $this->client->createPayment($payload);
                if (is_object($resp)) $resp = json_decode(json_encode($resp), true);
                if (!is_array($resp)) {
                    throw new \RuntimeException('Unexpected SDK response type for createPayment');
                }
                return $resp;
            } catch (\Throwable $e) {
                try { Logger::warn('SDK createPayment failed, falling back to HTTP', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
                // continue to HTTP fallback
            }
        }

        $token = $this->getToken();
        $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/payments/payment';

        $body = json_encode($payload);
        if ($body === false) {
            $err = json_last_error_msg();
            $ex = new \RuntimeException('JSON encode failed: ' . $err);
            try { Logger::systemError($ex, null, null, ['phase' => 'createPayment.json_encode']); } catch (\Throwable $_) {}
            throw $ex;
        }

        // headers with UA + Content-Length
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: MyShop/GoPaySdkWrapper/1.0',
            'Content-Length: ' . strlen($body),
        ];

        // log sanitized payload
        try { Logger::info('GoPay createPayment payload', null, [$this->sanitizeForLog($payload)]); } catch (\Throwable $_) {}

        // first attempt
        $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
        $httpCode = $resp['http_code'];
        $json = $resp['json'] ?? null;
        $raw = $resp['body'] ?? '';

        // if 401 -> try refresh token once (clear cache + get new token + retry)
        if ($httpCode === 401) {
            try { Logger::info('Received 401, clearing token cache and retrying once', null, []); } catch (\Throwable $_) {}
            $this->clearTokenCache();
            $token = $this->getToken();
            $headers[0] = 'Authorization: Bearer ' . $token; // keep ordering consistent (first header)
            $headers[3] = 'Content-Length: ' . strlen($body); // oprav místo asoc. klíče
            $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
            $httpCode = $resp['http_code'];
            $json = $resp['json'] ?? null;
            $raw = $resp['body'] ?? '';
        }

        // log response sanitized
        try { Logger::info('GoPay createPayment response', null, [$this->sanitizeForLog($json ?? $raw)]); } catch (\Throwable $_) {}

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) ? ($json['error_description'] ?? ($json['message'] ?? json_encode($json))) : $raw;
            $ex = new \RuntimeException("GoPay returned HTTP {$httpCode}: {$msg}");
            try { Logger::systemError($ex, null, null, ['phase' => 'createPayment', 'http_code' => $httpCode]); } catch (\Throwable $_) {}
            throw $ex;
        }

        if (!is_array($json)) {
            $ex = new \RuntimeException("GoPay returned non-JSON body (HTTP {$httpCode}): {$raw}");
            try { Logger::systemError($ex, null, null, ['phase' => 'createPayment', 'http_code' => $httpCode]); } catch (\Throwable $_) {}
            throw $ex;
        }

        if (!isset($json['id']) && !isset($json['paymentId'])) {
            $ex = new \RuntimeException("GoPay response missing payment id. Response: " . json_encode($json));
            try { Logger::systemError($ex, null, null, ['phase' => 'createPayment', 'response' => $this->sanitizeForLog($json)]); } catch (\Throwable $_) {}
            throw $ex;
        }

        return $json;
    }

    /**
     * Get status of payment.
     *
     * @param string $gatewayPaymentId
     * @return array
     */
    public function getStatus(string $gatewayPaymentId): array
    {
        if ($this->client !== null && method_exists($this->client, 'getStatus')) {
            try {
                $resp = $this->client->getStatus($gatewayPaymentId);
                if (is_object($resp)) $resp = json_decode(json_encode($resp), true);
                if (!is_array($resp)) throw new \RuntimeException('Unexpected SDK response type for getStatus');
                return $resp;
            } catch (\Throwable $e) {
                try { Logger::warn('SDK getStatus failed, falling back to HTTP', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            }
        }

        $token = $this->getToken();
        $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/payments/' . rawurlencode($gatewayPaymentId) . '/status';
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $resp = $this->doRequest('GET', $url, $headers, null, ['expect_json' => true, 'raw' => true]);
        $httpCode = $resp['http_code'];
        $json = $resp['json'] ?? null;
        $raw = $resp['body'] ?? '';

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) ? ($json['error_description'] ?? ($json['message'] ?? json_encode($json))) : $raw;
            $ex = new \RuntimeException("GoPay getStatus returned HTTP {$httpCode}: {$msg}");
            try { Logger::systemError($ex, null, null, ['phase' => 'getStatus', 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            throw $ex;
        }

        if (!is_array($json)) {
            $ex = new \RuntimeException("GoPay getStatus returned non-JSON body (HTTP {$httpCode}): {$raw}");
            try { Logger::systemError($ex, null, null, ['phase' => 'getStatus', 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            throw $ex;
        }

        return $json;
    }

    /**
     * Refund payment.
     *
     * @param string $gatewayPaymentId
     * @param array $args
     * @return array
     */
    public function refundPayment(string $gatewayPaymentId, array $args): array
    {
        if ($this->client !== null && method_exists($this->client, 'refundPayment')) {
            try {
                $resp = $this->client->refundPayment($gatewayPaymentId, $args);
                if (is_object($resp)) $resp = json_decode(json_encode($resp), true);
                if (!is_array($resp)) throw new \RuntimeException('Unexpected SDK response type for refundPayment');
                return $resp;
            } catch (\Throwable $e) {
                try { Logger::warn('SDK refundPayment failed, falling back to HTTP', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            }
        }

        $token = $this->getToken();
        $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/payments/' . rawurlencode($gatewayPaymentId) . '/refund';
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $body = json_encode($args);
        if ($body === false) {
            $ex = new \RuntimeException('JSON encode failed for refund args: ' . json_last_error_msg());
            try { Logger::systemError($ex, null, null, ['phase' => 'refundPayment']); } catch (\Throwable $_) {}
            throw $ex;
        }

        $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
        $httpCode = $resp['http_code'];
        $json = $resp['json'] ?? null;
        $raw = $resp['body'] ?? '';

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) ? ($json['error_description'] ?? ($json['message'] ?? json_encode($json))) : $raw;
            $ex = new \RuntimeException("GoPay refund returned HTTP {$httpCode}: {$msg}");
            try { Logger::systemError($ex, null, null, ['phase' => 'refundPayment', 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            throw $ex;
        }

        if (!is_array($json)) {
            $ex = new \RuntimeException("GoPay refund returned non-JSON body (HTTP {$httpCode}): {$raw}");
            try { Logger::systemError($ex, null, null, ['phase' => 'refundPayment', 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            throw $ex;
        }

        return $json;
    }

    /* ---------------- helper methods ---------------- */
    /**
     * Clear cached token (best-effort).
     */
    private function clearTokenCache(): void
    {
        try {
            if (method_exists($this->cache, 'delete')) {
                $this->cache->delete($this->cacheKey);
            } else {
                // set to null with 0 TTL as fallback
                $this->cache->set($this->cacheKey, null, 0);
            }
        } catch (\Throwable $_) {
            // silent
        }
    }
    /**
     * Central HTTP with retry/backoff.
     */
    private function doRequest(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        $attempts = $options['attempts'] ?? 3;
        $backoffMs = $options['backoff_ms'] ?? 200;
        $expectJson = $options['expect_json'] ?? false;
        $raw = $options['raw'] ?? false;

        $lastEx = null;
        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $options['ssl_verify_peer'] ?? true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = null;
            if ($resp === false) {
                $curlErr = curl_error($ch);
            }
            curl_close($ch);

            if ($resp === false) {
                $lastEx = new \RuntimeException('CURL error: ' . $curlErr);
                try { Logger::warn('HTTP request failed (curl)', null, ['url' => $url, 'error' => $curlErr, 'attempt' => $i + 1]); } catch (\Throwable $_) {}
                usleep($backoffMs * 1000);
                $backoffMs *= 2;
                continue;
            }

            $decoded = null;
            if ($expectJson || $raw) {
                $decoded = json_decode($resp, true);
            } else {
                $tmp = json_decode($resp, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded = $tmp;
                }
            }

            return ['http_code' => $httpCode, 'body' => $resp, 'json' => $decoded];
        }

        $ex = new \RuntimeException('HTTP request failed after retries: ' . ($lastEx ? $lastEx->getMessage() : 'unknown'));
        try { Logger::systemError($ex, null, null, ['phase' => 'doRequest', 'url' => $url]); } catch (\Throwable $_) {}
        throw $ex;
    }

    /**
     * Simple sanitizer for logging payloads/responses.
     */
    private function sanitizeForLog($data)
    {
        if (is_array($data)) {
            $copy = $data;
            $sensitive = ['clientSecret','client_secret','card_number','cardnum','cc_number','ccnum','cvv','cvc','payment_method_token','access_token','refresh_token','clientId','client_id','secret'];
            array_walk_recursive($copy, function (&$v, $k) use ($sensitive) {
                if (in_array($k, $sensitive, true)) {
                    $v = '[REDACTED]';
                }
            });
            return $copy;
        }
        return $data;
    }
}