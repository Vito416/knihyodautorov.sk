<?php
declare(strict_types=1);

/**
 * Final adapter for GoPay integration.
 *
 * Drop into: libs/GoPayAdapter.php
 *
 * NOTE: adapt GoPay SDK method names if they differ (createPayment, getStatus, refundPayment).
 */
final class GoPayAdapter
{
    private Database $db;
    private object $gopayClient; // instance of your GoPay SDK client
    private object $logger;      // expected methods: info(), warn(), systemError(), systemMessage()
    private ?object $mailer;     // optional Mailer for notifications
    private string $notificationUrl;
    private string $returnUrl;
    private int $reservationTtlSec = 900; // 15 minutes default

    public function __construct(
        Database $db,
        PaymentGatewayInterface $gopayClient,
        object $logger,
        ?object $mailer = null,
        string $notificationUrl = '',
        string $returnUrl = ''
    ) {
        $this->db = $db;
        $this->gopayClient = $gopayClient;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->notificationUrl = $notificationUrl;
        $this->returnUrl = $returnUrl;
    }

    /*
    Notes:
    - $gopayClient must implement PaymentGatewayInterface (the wrapper above does).
    - If your existing adapter still typehints `object`, it's fine — this snippet is optional but improves static checks.
    */
    
    /**
     * Fetch order items and normalise structure for payment payload.
     *
     * Returns array of items like:
     *  [
     *    ['title' => '...', 'price_snapshot' => 12.34, 'qty' => 1],
     *    ...
     *  ]
     */
    private function fetchOrderItemsForPayload(int $orderId): array
    {
        try {
            // join na books pokud existuje pro hezčí název, fallback na order_items sloupce
            $sql = 'SELECT oi.*, b.title AS book_title
                    FROM order_items oi
                    LEFT JOIN books b ON b.id = oi.book_id
                    WHERE oi.order_id = :oid';
            $rows = $this->db->fetchAll($sql, [':oid' => $orderId]);

            $out = [];
            foreach ($rows as $r) {
                $title = $r['book_title'] 
                        ?? $r['title'] 
                        ?? $r['name'] 
                        ?? ($r['product_name'] ?? 'item');

                // možná máš ukládáno jako string decimal nebo integer (cents) — snažíme se být tolerantní
                $price = 0.0;
                if (isset($r['price_snapshot']) && $r['price_snapshot'] !== null) {
                    $price = (float)$r['price_snapshot'];
                } elseif (isset($r['unit_price']) && $r['unit_price'] !== null) {
                    $price = (float)$r['unit_price'];
                } elseif (isset($r['price']) && $r['price'] !== null) {
                    $price = (float)$r['price'];
                }

                $qty = (int)($r['qty'] ?? $r['quantity'] ?? 1);

                $out[] = [
                    'title' => (string)$title,
                    'price_snapshot' => $price,
                    'qty' => max(1, $qty),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            // non-fatal: loguj a vrať prázdné pole
            try { $this->logger->systemError($e, null, null, ['phase' => 'fetchOrderItemsForPayload', 'order_id' => $orderId]); } catch (\Throwable $_) {}
            return [];
        }
    }
    /**
     * Create a payment for an existing order (order must be created and reservations attached).
     * Returns ['payment_id'=>int, 'redirect_url'=>string, 'gopay'=>mixed]
     */
    public function createPaymentFromOrder(int $orderId, string $idempotencyKey): array
    {
        $idempHash = hash('sha256', (string)$idempotencyKey);

        // idempotency quick-check
        $cached = $this->db->fetch('SELECT response FROM idempotency_keys WHERE key_hash = :k', [':k' => $idempHash]);
        if ($cached !== null && !empty($cached['response'])) {
            try { $this->logger->info('Idempotent createPaymentFromOrder hit', null, ['order_id' => $orderId]); } catch (\Throwable $_) {}
            return json_decode($cached['response'], true);
        }

        // Load order (lock row)
        $order = $this->db->fetch('SELECT * FROM orders WHERE id = :id FOR UPDATE', [':id' => $orderId]);
        if ($order === null) {
            throw new \RuntimeException('Order not found: ' . $orderId);
        }
        if ($order['status'] !== 'pending') {
            throw new \RuntimeException('Order not in pending state: ' . $orderId);
        }

        // Compose payment payload
        $amountCents = (int)round((float)$order['total'] * 100);
        $payload = [
            'amount' => $amountCents,
            'currency' => $order['currency'] ?? 'EUR',
            'order_number' => $order['uuid'] ?? (string)$order['id'],
            'callback' => [
                'return_url' => $this->returnUrl,
                'notification_url' => $this->notificationUrl,
            ],
            'order_description' => 'Objednávka ' . ($order['uuid'] ?? $order['id']),
            'items' => array_map(function($it){
                return [
                    'name' => $it['title'] ?? 'item',
                    'amount' => (int)round(((float)($it['price_snapshot'] ?? 0.0))*100),
                    'count' => (int)($it['qty'] ?? 1)
                ];
            }, $this->fetchOrderItemsForPayload($orderId)),
        ];

        // Create GoPay payment
        try {
            try { 
                $this->logger->info('Calling GoPay createPayment', null, ['order_id' => $orderId, 'payload' => $this->sanitizeForLog($payload)]); 
            } catch (\Throwable $_) {}

            $gopayResponse = $this->gopayClient->createPayment($payload);

            if (is_object($gopayResponse) && method_exists($gopayResponse, 'hasSucceed') && !$gopayResponse->hasSucceed()) {
                $body = $gopayResponse->json ?? null;
                try { 
                    $this->logger->systemError(new \RuntimeException('GoPay createPayment failed'), null, null, ['response' => $body]); 
                } catch (\Throwable $_) {}
                throw new \RuntimeException('Payment gateway error');
            }

            $gopayJson = is_array($gopayResponse) 
                ? $gopayResponse 
                : (is_object($gopayResponse) ? get_object_vars($gopayResponse) : []);

        } catch (\Throwable $e) {
            try { $this->logger->systemError($e, null, null, ['phase' => 'gopay.createPayment', 'order_id' => $orderId]); } catch (\Throwable $_) {}
            throw $e;
        }

        // Persist payment + idempotency inside single transaction
        $this->db->transaction(function (Database $d) use ($orderId, $order, $gopayResponse, $idempHash) {
            $gwId = $this->extractGatewayPaymentId($gopayResponse);
            $detailsJson = json_encode($gopayResponse);

            // Insert payment
            $d->prepareAndRun(
                'INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, created_at) 
                VALUES (:oid, :gw, :tx, :st, :amt, :cur, :det, NOW())',
                [
                    ':oid' => $orderId,
                    ':gw' => 'gopay',
                    ':tx' => $gwId,
                    ':st' => 'pending',
                    ':amt' => $this->safeDecimal($order['total']),
                    ':cur' => $order['currency'] ?? 'EUR',
                    ':det' => $detailsJson
                ]
            );

            $paymentId = (int)$d->lastInsertId();
            $redirectUrl = $this->extractRedirectUrl($gopayResponse);

            // Persist idempotency key (only once)
            $payloadResp = json_encode([
                'payment_id' => $paymentId,
                'redirect_url' => $redirectUrl,
                'gopay' => $gopayResponse
            ]);

            try {
                $d->prepareAndRun(
                    'INSERT INTO idempotency_keys (key_hash, user_id, request_hash, response, ttl_seconds, created_at)
                    VALUES (:k, NULL, NULL, :r, 86400, NOW())
                    ON DUPLICATE KEY UPDATE response = :r',
                    [':k' => $idempHash, ':r' => $payloadResp]
                );
            } catch (\Throwable $_) {
                // ignore duplicate key / DB issues
            }
        });

        // Return structured response
        $paymentId = $this->findPaymentIdByGatewayId($this->extractGatewayPaymentId($gopayResponse));
        $redirectUrl = $this->extractRedirectUrl($gopayResponse);

        return [
            'payment_id' => $paymentId,
            'redirect_url' => $redirectUrl,
            'gopay' => $gopayResponse
        ];
    }

    /**
     * Handle incoming webhook/notification raw body and headers.
     * Returns array with processing outcome.
     *
     * This method will:
     *  - verify the notification by fetching authoritative status from GoPay
     *  - deduplicate by payload hash
     *  - atomically update payments/orders/reservations
     *  - AFTER commit generate download tokens for digital assets and enqueue email(s)
     */
    public function handleNotify(string $rawBody, array $headers = []): array
    {
        $payloadHash = $this->computePayloadHash($rawBody);

        // dedupe based on payments.webhook_payload_hash or payment_logs
        $exists = $this->db->fetch('SELECT id FROM payments WHERE webhook_payload_hash = :h LIMIT 1', [':h' => $payloadHash]);
        if ($exists !== null) {
            try { $this->logger->info('Duplicate webhook ignored', null, ['hash' => $payloadHash]); } catch (\Throwable $_) {}
            return ['status' => 'ignored', 'reason' => 'duplicate'];
        }

        $decoded = json_decode($rawBody, true);
        $gwId = $decoded['paymentId'] ?? $decoded['id'] ?? null;

        // Always verify via GoPay API (authoritative)
        try {
            try { $this->logger->info('Verifying webhook via GoPay getStatus', null, ['gopay_id' => $gwId]); } catch (\Throwable $_) {}
            $status = $this->gopayClient->getStatus($gwId);
        } catch (\Throwable $e) {
            try { $this->logger->systemError($e, null, null, ['phase' => 'gopay.getStatus', 'gopay_id' => $gwId]); } catch (\Throwable $_) {}
            throw $e;
        }

        $mapping = $this->mapGatewayStatusToLocal($status);

        // Prepare data to be used AFTER transaction (email + tokens)
        $postActions = ['emails' => []];

        // Atomically update payment/order and confirm reservations if paid
        $this->db->transaction(function (Database $d) use ($gwId, $status, $payloadHash, $mapping, &$postActions) {
            $payment = $d->fetch('SELECT * FROM payments WHERE transaction_id = :tx AND gateway = :gw', [':tx' => $gwId, ':gw' => 'gopay']);
            if ($payment === null) {
                // create a payment record if missing (best effort)
                $d->prepareAndRun('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, webhook_payload_hash, raw_webhook, created_at) VALUES (NULL, :gw, :tx, :st, 0, "EUR", :det, :h, :raw, NOW())', [':gw' => 'gopay', ':tx' => $gwId, ':st' => $mapping['payment_status'], ':det' => json_encode($status), ':h' => $payloadHash, ':raw' => json_encode($status)]);
                $paymentId = (int)$d->lastInsertId();
                $payment = $d->fetch('SELECT * FROM payments WHERE id = :id', [':id' => $paymentId]);
            } else {
                $d->prepareAndRun('UPDATE payments SET status = :st, webhook_payload_hash = :h, raw_webhook = :raw, updated_at = NOW() WHERE id = :id', [':st' => $mapping['payment_status'], ':h' => $payloadHash, ':raw' => json_encode($status), ':id' => $payment['id']]);
                $paymentId = (int)$payment['id'];
            }

            // if mapped to paid and payment.order_id is set, confirm reservations and decrement stock
            $orderId = $payment['order_id'] ?? null;
            if ($orderId !== null && $mapping['order_status'] === 'paid') {
                // mark reservations and decrement stock
                $reservations = $d->fetchAll('SELECT * FROM inventory_reservations WHERE order_id = :oid AND status = "pending"', [':oid' => $orderId]);
                foreach ($reservations as $r) {
                    // decrement stock (safe UPDATE)
                    $d->prepareAndRun('UPDATE books SET stock_quantity = stock_quantity - :q WHERE id = :bid AND stock_quantity >= :q', [':q' => $r['qty'], ':bid' => $r['book_id']]);
                    $d->prepareAndRun('UPDATE inventory_reservations SET status = "confirmed" WHERE id = :id', [':id' => $r['id']]);
                }

                $d->prepareAndRun('UPDATE orders SET status = "paid", updated_at = NOW() WHERE id = :id', [':id' => $orderId]);

                // Prepare download token generation: find downloadable assets for this order
                $assets = $d->fetchAll('SELECT ba.id AS asset_id, ba.book_id, ba.filename FROM book_assets ba JOIN order_items oi ON oi.book_id = ba.book_id WHERE oi.order_id = :oid AND ba.asset_type IN ("pdf","epub","mobi","sample")', [':oid' => $orderId]);

                if (!empty($assets)) {
                    // Try to get customer email: prefer order.encrypted_customer_blob or user table
                    $email = null;
                    $orderRow = $d->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);

                    if (!empty($orderRow['encrypted_customer_blob'])) {
                        try {
                            $plain = Crypto::decrypt($orderRow['encrypted_customer_blob']);
                            $customer = json_decode($plain, true);
                            $email = $customer['email'] ?? null;
                        } catch (\Throwable $_) {
                            // decryption failed — fallback
                            $email = null;
                        }
                    }

                    if ($email === null && !empty($orderRow['user_id'])) {
                        $user = $d->fetch('SELECT email_enc FROM pouzivatelia WHERE id = :id', [':id' => $orderRow['user_id']]);
                        if (!empty($user['email_enc'])) {
                            try {
                                $email = Crypto::decrypt($user['email_enc']);
                            } catch (\Throwable $_) {
                                $email = null;
                            }
                        }
                    }

                    // Build download tokens and insert to order_item_downloads
                    $downloadEntries = [];
                    foreach ($assets as $a) {
                        // generate plaintext token (url-safe)
                        $tokenRaw = bin2hex(random_bytes(32));
                        // derive HMAC using KeyManager
                        try {
                            $hinfo = KeyManager::deriveHmacWithLatest('DOWNLOAD_TOKEN_KEY', null, 'download_token', $tokenRaw);
                            $tokenHash = $hinfo['hash']; // binary
                            $tokenKeyVer = $hinfo['version'] ?? null;
                        } catch (\Throwable $_) {
                            // fallback to sha256 if KeyManager unavailable
                            $tokenHash = hash('sha256', $tokenRaw, true);
                            $tokenKeyVer = null;
                        }

                        $expiresAt = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
                        $maxUses = 5;

                        $d->prepareAndRun('INSERT INTO order_item_downloads (order_id, book_id, asset_id, download_token_hash, encryption_key_version, token_key_version, max_uses, used, expires_at, created_at) VALUES (:oid, :bid, :aid, :hash, NULL, :tkv, :max, 0, :exp, NOW())', [
                            ':oid' => $orderId,
                            ':bid' => $a['book_id'],
                            ':aid' => $a['asset_id'],
                            ':hash' => $tokenHash,
                            ':tkv' => $tokenKeyVer,
                            ':max' => $maxUses,
                            ':exp' => $expiresAt
                        ]);

                        $downloadEntries[] = [
                            'book_id' => $a['book_id'],
                            'asset_id' => $a['asset_id'],
                            'filename' => $a['filename'],
                            'token_plain' => $tokenRaw,
                            'expires_at' => $expiresAt,
                            'max_uses' => $maxUses,
                            'token_key_version' => $tokenKeyVer
                        ];
                    }

                    // prepare email action data only if we have an address
                    if (!empty($email)) {
                        $postActions['emails'][] = ['order_id' => $orderId, 'email' => $email, 'downloads' => $downloadEntries, 'bill_name' => $orderRow['bill_full_name'] ?? null];
                    }
                }
            }
        }); // end transaction

        // After commit: send emails (if any)
        foreach ($postActions['emails'] as $em) {
            try {
                $this->enqueueOrderDownloadsEmail($em['order_id'], $em['email'], $em['downloads'], $em['bill_name'] ?? null);
            } catch (\Throwable $e) {
                try { $this->logger->systemMessage('error', 'Failed to enqueue order downloads email', null, ['order_id' => $em['order_id'], 'exception' => (string)$e]); } catch (\Throwable $_) {}
            }
        }

        try { $this->logger->info('Webhook processed', null, ['gopay_id' => $gwId, 'payload_hash' => $payloadHash]); } catch (\Throwable $_) {}
        return ['status' => 'processed', 'gopay_id' => $gwId];
    }

    public function fetchStatus(string $gopayPaymentId): array
    {
        try {
            return $this->gopayClient->getStatus($gopayPaymentId);
        } catch (\Throwable $e) {
            try { $this->logger->systemError($e, null, null, ['phase' => 'gopay.getStatus', 'id' => $gopayPaymentId]); } catch (\Throwable $_) {}
            throw $e;
        }
    }

    public function refundPayment(string $gopayPaymentId, float $amount): array
    {
        try {
            $amt = (int)round($amount * 100);
            return $this->gopayClient->refundPayment($gopayPaymentId, ['amount' => $amt]);
        } catch (\Throwable $e) {
            try { $this->logger->systemError($e, null, null, ['phase' => 'gopay.refund', 'id' => $gopayPaymentId]); } catch (\Throwable $_) {}
            throw $e;
        }
    }

    /* ---------------- helper methods ---------------- */

    private function computePayloadHash(string $body): string
    {
        return hash('sha256', $body);
    }

    private function sanitizeForLog(array $a): array
    {
        // remove sensitive fields if present
        unset($a['card_number'], $a['cvv'], $a['payment_method_token']);
        return $a;
    }

    private function extractGatewayPaymentId($gopayResponse): string
    {
        if (is_array($gopayResponse)) {
            return (string)($gopayResponse['id'] ?? $gopayResponse['paymentId'] ?? $gopayResponse['payment']['id'] ?? '');
        }
        if (is_object($gopayResponse)) {
            $props = get_object_vars($gopayResponse);
            return (string)($props['id'] ?? $props['paymentId'] ?? '');
        }
        return (string)$gopayResponse;
    }

    private function extractRedirectUrl($gopayResponse): ?string
    {
        if (is_array($gopayResponse)) {
            // handle sandbox format
            if (isset($gopayResponse[0]['gw_url'])) return $gopayResponse[0]['gw_url'];
            return $gopayResponse['gw_url'] ?? $gopayResponse['payment_redirect'] ?? $gopayResponse['redirect_url'] ?? null;
        }
        if (is_object($gopayResponse)) {
            return $gopayResponse->gw_url ?? $gopayResponse->payment_redirect ?? $gopayResponse->redirect_url ?? null;
        }
        return null;
    }

    private function findPaymentIdByGatewayId(string $gwId): ?int
    {
        $row = $this->db->fetch('SELECT id FROM payments WHERE transaction_id = :tx AND gateway = :gw LIMIT 1', [':tx' => $gwId, ':gw' => 'gopay']);
        return $row['id'] ?? null;
    }

    private function safeDecimal($v): string
    {
        return number_format((float)$v, 2, '.', '');
    }

    private function mapGatewayStatusToLocal($status): array
    {
        // naive mapping — adapt after inspecting GoPay status object
        $state = null;
        if (is_array($status)) {
            $state = strtolower($status['state'] ?? $status['paymentState'] ?? '') ;
        } elseif (is_object($status)) {
            $state = strtolower($status->state ?? $status->paymentState ?? '');
        }

        if (in_array($state, ['paid', 'completed', 'ok'], true)) {
            return ['payment_status' => 'paid', 'order_status' => 'paid'];
        }
        if ($state === 'authorized') {
            return ['payment_status' => 'authorized', 'order_status' => 'pending'];
        }
        if (in_array($state, ['cancelled', 'failed', 'declined'], true)) {
            return ['payment_status' => 'failed', 'order_status' => 'cancelled'];
        }

        return ['payment_status' => 'pending', 'order_status' => 'pending'];
    }

    /**
     * Enqueue an email with download links using your Mailer::enqueue format
     */
    private function enqueueOrderDownloadsEmail(int $orderId, string $toEmail, array $downloads, ?string $billName = null): void
    {
        if (!class_exists('Mailer') || !method_exists('Mailer', 'enqueue')) {
            try { $this->logger->warn('Mailer::enqueue not available, cannot enqueue order downloads email', null, ['order_id' => $orderId]); } catch (\Throwable $_) {}
            return;
        }

        // Prepare variables for template
        $givenName = '';
        if (!empty($billName)) {
            $parts = preg_split('/\s+/', trim($billName));
            $givenName = $parts[0] ?? '';
        }

        $links = [];
        foreach ($downloads as $d) {
            $links[] = $this->buildDownloadUrl($orderId, $d['token_plain']);
        }

        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $subject = 'Vaša objednávka je spracovaná — stiahnite si zakúpené súbory';

        $payloadArr = [
            'user_id' => null,
            'to' => $toEmail,
            'subject' => $subject,
            'template' => 'order_downloads',
            'vars' => [
                'given_name' => $givenName,
                'order_id' => $orderId,
                'download_links' => $links,
            ],
            'attachments' => [
                [
                    'type' => 'inline_remote',
                    'src'  => ($base . '/assets/logo.png'),
                    'name' => 'logo.png',
                    'cid'  => 'logo'
                ]
            ],
            'meta' => [
                'cipher_format' => 'aead_xchacha20poly1305_v1_binary'
            ],
        ];

        try {
            $notifId = Mailer::enqueue($payloadArr);
            try { $this->logger->systemMessage('notice', 'Order downloads email enqueued', null, ['order_id' => $orderId, 'notification_id' => $notifId]); } catch (\Throwable $_) {}
        } catch (\Throwable $e) {
            try { $this->logger->systemMessage('error', 'Mailer enqueue failed during order downloads', null, ['order_id' => $orderId, 'exception' => (string)$e]); } catch (\Throwable $_) {}
            // do not throw — email failure is non-fatal for payment processing
        }
    }

    private function buildDownloadUrl(int $orderId, string $token): string
    {
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        // public endpoint that will validate token and stream file
        return $base . '/download?order_id=' . rawurlencode((string)$orderId) . '&token=' . rawurlencode($token);
    }

    private function fetchOrderTotal(int $orderId): float
    {
        $row = $this->db->fetch('SELECT total FROM orders WHERE id = :id', [':id' => $orderId]);
        return $row ? (float)$row['total'] : 0.0;
    }
}