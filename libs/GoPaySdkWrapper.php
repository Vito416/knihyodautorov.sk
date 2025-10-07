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
final class GoPayTokenException extends \RuntimeException {}
final class GoPayHttpException extends \RuntimeException {}
final class GoPayPaymentException extends \RuntimeException {}

final class GoPaySdkWrapper implements PaymentGatewayInterface
{
    private array $cfg;
    private ?object $client = null;
    private FileCache $cache;
    private string $cacheKey = 'gopay_oauth_token';
    private const PERMANENT_TOKEN_ERRORS = [
    'invalid_client',
    'invalid_grant',
    'unauthorized_client',
    'invalid_request',
    'unsupported_grant_type',
    'invalid_scope',
    ];

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
     * Safe JSON encode helper (throws on error).
     */
    private function safeJsonEncode(mixed $v): string
    {
        $s = json_encode($v);
        if ($s === false) {
            $msg = json_last_error_msg();
            $ex = new \RuntimeException('JSON encode failed: ' . $msg);
            try { Logger::systemError($ex, null, null, ['phase' => 'json_encode']); } catch (\Throwable $_) {}
            throw $ex;
        }
        return $s;
    }

    /**
     * Build HTTP header array from assoc map to avoid fragile numeric indices.
     * @param array $assoc e.g. ['Authorization'=>'Bearer x', 'Content-Type'=>'application/json']
     * @return array ['Key: Value', ...]
     */
    private function buildHeaders(array $assoc): array
    {
        $out = [];
        foreach ($assoc as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }

    /**
     * Get OAuth token (cached).
     *
     * @return string
     * @throws \RuntimeException
     */
    public function getToken(): string
    {
        // fast path
        $tokenData = null;
        try { $tokenData = $this->cache->get($this->cacheKey); } catch (\Throwable $_) { $tokenData = null; }
        if (is_array($tokenData) && isset($tokenData['token'], $tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
            return (string)$tokenData['token'];
        }

        $lockKey = 'gopay_token_lock';
        $fp = null;
        $lockToken = null;
        $haveCacheLock = false;

        // 1) try cache-provided lock API
        if (method_exists($this->cache, 'acquireLock')) {
            try {
                $lockToken = $this->cache->acquireLock($lockKey, 10);
                $haveCacheLock = $lockToken !== null;
            } catch (\Throwable $_) {
                $haveCacheLock = false;
                $lockToken = null;
            }
        }

        // 2) fallback to file lock if cache lock not available
        $tempLockPath = sys_get_temp_dir() . '/gopay_token_lock_' . md5($this->cfg['clientId']);
        if (!$haveCacheLock) {
            $fp = @fopen($tempLockPath, 'c+');
            if ($fp !== false) {
                $waitUntil = microtime(true) + 10.0; // 10s
                $got = false;
                while (microtime(true) < $waitUntil) {
                    if (flock($fp, LOCK_EX | LOCK_NB)) { $got = true; break; }
                    usleep(100_000 + random_int(0, 50_000));
                }
                if (!$got) {
                    try { Logger::info('Could not acquire file lock for token fetch, proceeding without it', null, ['lock' => $tempLockPath]); } catch (\Throwable $_) {}
                    fclose($fp); $fp = null;
                }
            }
        }

        try {
            if (!$haveCacheLock && $fp === null) {
                // we couldn't get a lock — short randomized sleep to reduce contention
                usleep((100000 + random_int(0, 200000))); // 100-300 ms
            }
            // double-check cache while holding lock
            try { $tokenData = $this->cache->get($this->cacheKey); } catch (\Throwable $_) { $tokenData = null; }
            if (is_array($tokenData) && isset($tokenData['token'], $tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
                return (string)$tokenData['token'];
            }

            $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/oauth2/token';
            $credentials = base64_encode($this->cfg['clientId'] . ':' . $this->cfg['clientSecret']);
            $body = http_build_query(['grant_type' => 'client_credentials', 'scope' => $this->cfg['scope']]);

            $attempts = 3;
            $backoffMs = 200;
            $lastEx = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $headers = $this->buildHeaders([
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                    'User-Agent' => 'KnihyOdAutorov/GoPaySdkWrapper/1.0',
                    'Expect' => '',
                    'Content-Length' => (string)strlen($body),
                ]);
                $resp = $this->doRequest('POST', $url, $headers, $body, ['raw' => true, 'expect_json' => true, 'attempts' => 1]);
                $httpCode = $resp['http_code'] ?? 0;
                $decoded = $resp['json'] ?? null;
                $raw = $resp['body'] ?? '';

                // Successful response
                if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded['access_token'], $decoded['expires_in'])) {
                    $expiresIn = (int)$decoded['expires_in'];
                    $safety = max(1, (int)($this->cfg['tokenTtlSafety'] ?? 10));
                    $ttl = max(1, $expiresIn - $safety);
                    try {
                        $this->cache->set($this->cacheKey, [
                            'token' => (string)$decoded['access_token'],
                            'expires_at' => time() + $expiresIn - $safety,
                        ], $ttl);
                    } catch (\Throwable $_) {}
                    return (string)$decoded['access_token'];
                }

                // If 4xx -> likely permanent (invalid client/credentials/etc.) — do not retry
                if ($httpCode >= 400 && $httpCode < 500) {
                    // try to extract specific error code from response body if available
                    $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
                    $errDesc = is_array($decoded) ? ($decoded['error_description'] ?? null) : null;

                    // treat common OAuth permanent errors as non-retriable
                    if ($err !== null && in_array($err, self::PERMANENT_TOKEN_ERRORS, true)) {
                        $msg = $errDesc ?: json_encode($decoded);
                        $ex = new GoPayTokenException("Permanent token error {$httpCode}: {$err} - {$msg}");
                        try { Logger::systemError($ex, null, null, ['phase' => 'getToken', 'http_code' => $httpCode, 'error' => $err]); } catch (\Throwable $_) {}
                        throw $ex;
                    }

                    // If we don't know the error code, still don't brute-force retry too much.
                    // Treat other 4xx as permanent to avoid useless retries.
                    $msg = is_array($decoded) ? ($decoded['error_description'] ?? json_encode($decoded)) : $raw;
                    $ex = new GoPayTokenException("GoPay token endpoint returned HTTP {$httpCode}: {$msg}");
                    try { Logger::systemError($ex, null, null, ['phase' => 'getToken', 'http_code' => $httpCode]); } catch (\Throwable $_) {}
                    throw $ex;
                }

                // For 5xx or unexpected status codes -> throw to outer catch and retry (transient)
                $msg = is_array($decoded) ? ($decoded['error_description'] ?? json_encode($decoded)) : $raw;
                throw new GoPayTokenException("GoPay token endpoint returned HTTP {$httpCode}: {$msg}");
            } catch (\Throwable $e) {
                $lastEx = $e;
                try { Logger::warn('getToken attempt failed', null, ['attempt' => $i + 1, 'exception' => (string)$e]); } catch (\Throwable $_) {}
                // exponential backoff for transient failures (but don't sleep after last attempt)
                if ($i < $attempts - 1) {
                    $backoffMs = min($backoffMs * 2, 2000);
                    usleep(($backoffMs + random_int(0, 250)) * 1000);
                }
            }
        }

            $ex = $lastEx ?? new GoPayTokenException('Unknown error obtaining token');
            try { Logger::systemError($ex, null, null, ['phase' => 'getToken']); } catch (\Throwable $_) {}
            throw new GoPayTokenException('Failed to obtain GoPay OAuth token: ' . $ex->getMessage());
        } finally {
            // release file lock
            if (isset($fp) && is_resource($fp)) {
                @fflush($fp);
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            // release cache lock if used
            if ($lockToken !== null && method_exists($this->cache, 'releaseLock')) {
                try { $this->cache->releaseLock($lockKey, $lockToken); } catch (\Throwable $_) {}
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
        // ensure target.type and goid present
        $goidVal = (string)$this->cfg['goid'];
        if (empty($payload['target'])) {
            $payload['target'] = ['type' => 'ACCOUNT', 'goid' => $goidVal];
        } else {
            $payload['target']['type'] = $payload['target']['type'] ?? 'ACCOUNT';
            $payload['target']['goid'] = (string)($payload['target']['goid'] ?? $goidVal);
        }

        // try SDK first
        if ($this->client !== null && method_exists($this->client, 'createPayment')) {
            try {
                $resp = $this->client->createPayment($payload);
                if (is_object($resp)) $resp = json_decode(json_encode($resp), true);
                if (!is_array($resp)) {
                    throw new GoPayPaymentException('Unexpected SDK response type for createPayment');
                }
                return $resp;
            } catch (\Throwable $e) {
                try { Logger::warn('SDK createPayment failed, falling back to HTTP', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
                // continue to HTTP fallback
            }
        }

        // HTTP fallback
        $token = $this->getToken();
        $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/payments/payment';
        $body = $this->safeJsonEncode($payload);

        $headerAssoc = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => 'KnihyOdAutorov/GoPaySdkWrapper/1.0',
            // avoid "Expect: 100-continue" delays
            'Expect'        => '',
            'Content-Length'=> (string)strlen($body),
        ];
        $headers = $this->buildHeaders($headerAssoc);

        try { Logger::info('GoPay createPayment payload', null, [$this->sanitizeForLog($payload)]); } catch (\Throwable $_) {}

        // perform request with single retry-on-401
        $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
        $httpCode = $resp['http_code'];
        $json = $resp['json'] ?? null;
        $raw = $resp['body'] ?? '';

        if ($httpCode === 401) {
            try { Logger::info('Received 401, clearing token cache and retrying once', null, []); } catch (\Throwable $_) {}
            $this->clearTokenCache();
            $token = $this->getToken();
            $headerAssoc['Authorization'] = 'Bearer ' . $token;
            $headerAssoc['Content-Length'] = (string)strlen($body);
            $headers = $this->buildHeaders($headerAssoc);
            $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
            $httpCode = $resp['http_code'];
            $json = $resp['json'] ?? null;
            $raw = $resp['body'] ?? '';
        }

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
        // --- safe cache key (no reserved chars) ---
        $statusCacheKey = 'gopay_status_' . substr(hash('sha256', $gatewayPaymentId), 0, 32);
        $cached = null;
        try {
            $cached = $this->cache->get($statusCacheKey);
        } catch (\Throwable $e) {
            try { Logger::warn('Status cache get failed', null, ['cache_key' => $statusCacheKey, 'exception' => (string)$e, 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            $cached = null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        // SDK path (unchanged)
        if ($this->client !== null && method_exists($this->client, 'getStatus')) {
            try {
                $resp = $this->client->getStatus($gatewayPaymentId);
                if (is_object($resp)) $resp = json_decode(json_encode($resp), true);
                if (!is_array($resp)) throw new \RuntimeException('Unexpected SDK response type for getStatus');
                try {
                    $this->cache->set($statusCacheKey, $resp, 60);
                } catch (\Throwable $e) {
                    try { Logger::warn('Failed to set status cache (SDK path)', null, ['cache_key' => $statusCacheKey, 'exception' => (string)$e]); } catch (\Throwable $_) {}
                }
                return $resp;
            } catch (\Throwable $e) {
                try { Logger::warn('SDK getStatus failed, falling back to HTTP', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            }
        }

        // HTTP fallback
        $token = $this->getToken();
        $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/payments/payment/' . rawurlencode($gatewayPaymentId);

        // NOTE: do NOT send Content-Type on GET — causes RESTEasy "Cannot consume content type" 500
        $headers = $this->buildHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'User-Agent' => 'KnihyOdAutorov/GoPaySdkWrapper/1.0',
            'Expect' => '',
        ]);

        $resp = $this->doRequest('GET', $url, $headers, null, ['expect_json' => true, 'raw' => true]);
        $httpCode = $resp['http_code'] ?? 0;
        $json = $resp['json'] ?? null;
        $raw = $resp['body'] ?? '';

        // retry once on 401 (token might be stale)
        if ($httpCode === 401) {
            try { Logger::info('getStatus received 401, clearing token and retrying', null, []); } catch (\Throwable $_) {}
            $this->clearTokenCache();
            $token = $this->getToken();
            $headers = $this->buildHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'KnihyOdAutorov/GoPaySdkWrapper/1.0',
                'Expect' => '',
            ]);
            $resp = $this->doRequest('GET', $url, $headers, null, ['expect_json' => true, 'raw' => true]);
            $httpCode = $resp['http_code'] ?? 0;
            $json = $resp['json'] ?? null;
            $raw = $resp['body'] ?? '';
        }

        try { Logger::info('GoPay getStatus response', null, [$this->sanitizeForLog($json ?? $raw)]); } catch (\Throwable $_) {}

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) ? ($json['error_description'] ?? ($json['message'] ?? json_encode($json))) : $raw;
            $ex = new \RuntimeException("GoPay getStatus returned HTTP {$httpCode}: {$msg}");
            try { Logger::systemError($ex, null, null, ['phase' => 'getStatus', 'id' => $gatewayPaymentId, 'http_code' => $httpCode]); } catch (\Throwable $_) {}
            throw $ex;
        }

        if (!is_array($json)) {
            $ex = new \RuntimeException("GoPay getStatus returned non-JSON body (HTTP {$httpCode}): {$raw}");
            try { Logger::systemError($ex, null, null, ['phase' => 'getStatus', 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            throw $ex;
        }

        // Safely try to set cache, but do not fail the call if cache set errors
        try {
            $this->cache->set($statusCacheKey, $json, 60);
        } catch (\Throwable $e) {
            try { Logger::warn('Failed to set status cache', null, ['cache_key' => $statusCacheKey, 'exception' => (string)$e, 'id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
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
        $url = rtrim($this->cfg['gatewayUrl'], '/') . '/api/payments/payment/' . rawurlencode($gatewayPaymentId) . '/refund';
        $headers = $this->buildHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Accept'        => 'application/json',
            'User-Agent' => 'KnihyOdAutorov/GoPaySdkWrapper/1.0',
            'Expect' => '',
        ]);

        $body = $this->safeJsonEncode($args);
        $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
        $httpCode = $resp['http_code'] ?? 0;
        $json = $resp['json'] ?? null;
        $raw = $resp['body'] ?? '';

        // retry once on 401
        if ($httpCode === 401) {
            try { Logger::info('refundPayment received 401, clearing token and retrying', null, []); } catch (\Throwable $_) {}
            $this->clearTokenCache();
            $token = $this->getToken();
            $headers = $this->buildHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'KnihyOdAutorov/GoPaySdkWrapper/1.0',
                'Expect' => '',
            ]);
            $resp = $this->doRequest('POST', $url, $headers, $body, ['expect_json' => true, 'raw' => true]);
            $httpCode = $resp['http_code'] ?? 0;
            $json = $resp['json'] ?? null;
            $raw = $resp['body'] ?? '';
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) ? ($json['error_description'] ?? ($json['message'] ?? json_encode($json))) : $raw;
            $ex = new \RuntimeException("GoPay refund returned HTTP {$httpCode}: {$msg}");
            try { Logger::systemError($ex, null, null, ['phase' => 'refundPayment', 'id' => $gatewayPaymentId, 'http_code' => $httpCode]); } catch (\Throwable $_) {}
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
        $timeout = $options['timeout'] ?? 15;

        $lastEx = null;
        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $options['ssl_verify_peer'] ?? true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

            $resp = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlErr = curl_error($ch);
            $info = curl_getinfo($ch);
            $httpCode = (int)$info['http_code'];

            // close the handle
            curl_close($ch);

            if ($resp === false || $curlErrNo !== 0) {
                $lastEx = new GoPayHttpException('CURL error: ' . $curlErr . ' (' . $curlErrNo . ')');
                try { Logger::warn('HTTP request failed (curl)', null, ['url' => $url, 'error' => $curlErr, 'errno' => $curlErrNo, 'attempt' => $i + 1, 'info' => $info]); } catch (\Throwable $_) {}
                $backoffMs = min($backoffMs * 2, 2000);
                usleep(($backoffMs + random_int(0, 250)) * 1000);
                continue;
            }

            // decode json when requested/possible
            $decoded = null;
            if ($expectJson) {
                $decoded = json_decode($resp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new GoPayHttpException('Invalid JSON response: ' . json_last_error_msg());
                }
            }
            return ['http_code' => $httpCode, 'body' => $resp, 'json' => $decoded];
        }

        $ex = new GoPayHttpException('HTTP request failed after retries: ' . ($lastEx ? $lastEx->getMessage() : 'unknown'));
        try { Logger::systemError($ex, null, null, ['phase' => 'doRequest', 'url' => $url]); } catch (\Throwable $_) {}
        throw $ex;
    }

    /**
     * Simple sanitizer for logging payloads/responses.
     */
    private function sanitizeForLog(array|string $data): array|string
    {
        if (is_array($data)) {
            $copy = $data;
            $sensitive = ['account','number','pan','email','phone','phone_number','iban','accountNumber','clientSecret','client_secret','card_number','cardnum','cc_number','ccnum','cvv','cvc','payment_method_token','access_token','refresh_token','clientId','client_id','secret'];
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