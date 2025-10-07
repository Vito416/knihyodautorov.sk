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
    private ?FileCache $cache = null; // optional FileCache for idempotency/status caching

    public function __construct(
        Database $db,
        PaymentGatewayInterface $gopayClient,
        object $logger,
        ?object $mailer = null,
        string $notificationUrl = '',
        string $returnUrl = '',
        ?FileCache $cache = null
    ) {
        $this->db = $db;
        $this->gopayClient = $gopayClient;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->notificationUrl = $notificationUrl;
        $this->returnUrl = $returnUrl;
        $this->cache = $cache; // may be null, check before use
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
        // require non-empty idempotency key (caller must provide it)
        if (trim((string)$idempotencyKey) === '') {
            throw new \InvalidArgumentException('idempotencyKey is required and must be non-empty');
        }
        $idempHash = hash('sha256', (string)$idempotencyKey);

        // unified idempotency quick-check via helper
        $cached = $this->lookupIdempotency($idempotencyKey);
        if ($cached !== null) {
            try { $this->logger->info('Idempotent createPaymentFromOrder hit (lookupIdempotency)', null, ['order_id' => $orderId]); } catch (\Throwable $_) {}
            return $cached;
        }

        // Load order (non-locking). We'll re-check & lock inside the short DB transaction
        $order = $this->db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
        if ($order === null) {
            throw new \RuntimeException('Order not found: ' . $orderId);
        }
        if ($order['status'] !== 'pending') {
            throw new \RuntimeException('Order not in pending state: ' . $orderId);
        }

        // Compose payment payload (keep your existing payload building)
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
            // items nastavíme níže
        ];
        $payload['items'] = array_map(function($it){
            return [
                'name' => $it['title'] ?? 'item',
                'amount' => (int)round(((float)($it['price_snapshot'] ?? 0.0))*100),
                'count' => (int)($it['qty'] ?? 1)
            ];
        }, $this->fetchOrderItemsForPayload($orderId));
        // verify totals (defensive)
        $sumItems = 0;
        foreach ($payload['items'] as $it) {
            if (!isset($it['amount']) || !is_int($it['amount']) || $it['amount'] < 0) {
                throw new \RuntimeException('Invalid item amount in payload');
            }
            $sumItems += $it['amount'] * max(1, (int)($it['count'] ?? 1));
        }
        if ($sumItems !== $payload['amount']) {
            // buď loguj a throw (STRICT), nebo jen warn
            try { $this->logger->warn('Payment amount mismatch between items and total', null, ['order_id'=>$orderId,'items_sum'=>$sumItems,'amount'=>$payload['amount']]); } catch (\Throwable $_) {}
            // volitelně: throw new \RuntimeException('Payment amount mismatch');
        }

        // 2) Provisionální payment row (with re-check & FOR UPDATE lock)
        $provisionPaymentId = null;
        try {
            $this->db->transaction(function (Database $d) use ($orderId, $order, &$provisionPaymentId) {
                // re-lock order and ensure still pending
                $row = $d->fetch('SELECT id, status, total, currency FROM orders WHERE id = :id FOR UPDATE', [':id' => $orderId]);
                if ($row === null) {
                    throw new \RuntimeException('Order disappeared during processing: ' . $orderId);
                }
                if ($row['status'] !== 'pending') {
                    throw new \RuntimeException('Order no longer pending: ' . $orderId);
                }

                $d->prepareAndRun(
                    'INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, created_at) 
                    VALUES (:oid, :gw, NULL, :st, :amt, :cur, NULL, NOW())',
                    [
                        ':oid' => $orderId,
                        ':gw' => 'gopay',
                        ':st' => 'initiated',
                        ':amt' => $this->safeDecimal($row['total']),
                        ':cur' => $row['currency'] ?? 'EUR'
                    ]
                );
                $provisionPaymentId = (int)$d->lastInsertId();
            });
        } catch (\Throwable $e) {
            try { $this->logger->systemError($e, null, null, ['phase'=>'provision_payment']); } catch (\Throwable $_) {}
            throw $e;
        }

        // 3) Call GoPay
        try {
            try { $this->logger->info('Calling GoPay createPayment', null, ['order_id' => $orderId, 'payload' => $this->sanitizeForLog($payload)]); } catch (\Throwable $_) {}
            $gopayResponse = $this->gopayClient->createPayment($payload);
        } catch (\Throwable $e) {
            // Attempt to mark provisioned payment as failed to aid reconciliation
            try {
                if ($provisionPaymentId !== null) {
                    $this->db->prepareAndRun('UPDATE payments SET status = :st, details = :det, updated_at = NOW() WHERE id = :id', [
                        ':st' => 'failed',
                        ':det' => json_encode(['error' => (string)$e]),
                        ':id' => $provisionPaymentId
                    ]);
                } else {
                    try { $this->logger->warn('Attempted to mark provisioned payment as failed but provisionPaymentId is null', null, ['order_id'=>$orderId]); } catch (\Throwable $_) {}
                }
            } catch (\Throwable $_) {}
            try { $this->logger->systemError($e, null, null, ['phase' => 'gopay.createPayment', 'order_id' => $orderId]); } catch (\Throwable $_) {}
            throw $e;
        }

        // 4) Persist gateway id/details and idempotency inside transaction
        $this->db->transaction(function (Database $d) use ($orderId, $gopayResponse, $provisionPaymentId, $idempHash) {
            $gwId = $this->extractGatewayPaymentId($gopayResponse);
            $detailsJson = json_encode($gopayResponse);
            if ($detailsJson === false) {
                $detailsJson = json_encode(['note' => 'unserializable_gopay_response']);
            }

            $d->prepareAndRun(
                'UPDATE payments SET transaction_id = :tx, status = :st, details = :det, updated_at = NOW() WHERE id = :id',
                [
                    ':tx' => $gwId,
                    ':st' => 'pending',
                    ':det' => $detailsJson,
                    ':id' => $provisionPaymentId
                ]
            );

            // persist idempotency key in DB (best-effort)
            try {
                $d->prepareAndRun(
                    'INSERT INTO idempotency_keys (key_hash, payment_id, ttl_seconds, created_at)
                    VALUES (:k, :pid, :ttl, NOW())
                    ON DUPLICATE KEY UPDATE payment_id = VALUES(payment_id), ttl_seconds = VALUES(ttl_seconds)',
                    [':k' => $idempHash, ':pid' => $provisionPaymentId, ':ttl' => 86400]
                );
            } catch (\Throwable $_) {
                // ignore duplicate key / DB issues
            }

            // DO NOT write FileCache inside DB transaction — write cache after commit using persistIdempotency()

        });
        // AFTER COMMIT: best-effort persist to FileCache (store structured array)
        try {
            $gopayForCache = null;
            if (is_array($gopayResponse)) {
                $gopayForCache = $gopayResponse;
            } elseif (is_object($gopayResponse)) {
                // convert object to array in safe way
                $gopayForCache = json_decode(json_encode($gopayResponse), true);
            } else {
                $gopayForCache = $gopayResponse;
            }

            $payloadArr = [
                'payment_id' => $provisionPaymentId,
                'redirect_url' => $this->extractRedirectUrl($gopayResponse),
                'gopay' => $gopayForCache,
                'order_id' => $orderId
            ];

            // persist both DB (already done) and FileCache (best-effort)
            if (!empty($provisionPaymentId) && (int)$provisionPaymentId > 0) {
                $this->persistIdempotency($idempotencyKey, $payloadArr, (int)$provisionPaymentId);
            } else {
                try { $this->logger->warn('persistIdempotency skipped: invalid provisionPaymentId', null, ['provisionPaymentId' => $provisionPaymentId, 'order_id' => $orderId]); } catch (\Throwable $_) {}
            }

        } catch (\Throwable $e) {
            try { $this->logger->warn('persistIdempotency after commit failed', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
        }

        // 5) Return structured response
        $gwId = $this->extractGatewayPaymentId($gopayResponse);
        $paymentId = $this->findPaymentIdByGatewayId($gwId);
        $redirectUrl = $this->extractRedirectUrl($gopayResponse);

        // fallback: pokud nelze najít row podle gateway id, vrať provision id (alespoň něco pro reconciliaci)
        if ($paymentId === null && isset($provisionPaymentId) && $provisionPaymentId !== null) {
            try { $this->logger->warn('Could not find payment by gateway id, returning provisional payment id as fallback', null, ['gw_id'=>$gwId,'provision_id'=>$provisionPaymentId]); } catch (\Throwable $_) {}
            $paymentId = $provisionPaymentId;
        }

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
        // Optional webhook signature verification (enable by setting GOPAY_WEBHOOK_SECRET)
        $secret = (string)($_ENV['GOPAY_WEBHOOK_SECRET'] ?? '');
        if ($secret !== '') {
            $sigHeader = $headers['X-GOPAY-SIGN'] ?? $headers['x-gopay-sign'] ?? $headers['Gopay-Signature'] ?? null;
            if ($sigHeader === null) {
                try { $this->logger->warn('Missing webhook signature header', null, ['hash' => $payloadHash]); } catch (\Throwable $_) {}
                throw new \RuntimeException('Missing webhook signature');
            }
            $expected = hash_hmac('sha256', $rawBody, $secret);
            if (!hash_equals($expected, $sigHeader)) {
                try { $this->logger->warn('Invalid webhook signature', null, ['hash' => $payloadHash]); } catch (\Throwable $_) {}
                throw new \RuntimeException('Invalid webhook signature');
            }
        }
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
            if (empty($gwId)) {
            try { $this->logger->warn('Webhook missing gateway id (paymentId/id)', null, ['payload_hash' => $payloadHash, 'raw' => $rawBody]); } catch (\Throwable $_) {}
            throw new \RuntimeException('Webhook missing gateway payment id');
        }
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
                $d->prepareAndRun('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, webhook_payload_hash, raw_webhook, created_at) VALUES (NULL, :gw, :tx, :st, 0, :cur, :det, :h, :raw, NOW())', [
                    ':gw' => 'gopay',
                    ':tx' => $gwId,
                    ':st' => $mapping['payment_status'],
                    ':cur' => 'EUR',
                    ':det' => json_encode($status),
                    ':h' => $payloadHash,
                    ':raw' => json_encode($status)
                ]);
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
                // lock the book row and check stock atomically
                $bookRow = $d->fetch('SELECT stock_quantity FROM books WHERE id = :bid FOR UPDATE', [
                    ':bid' => $r['book_id']
                ]);

                if ($bookRow === null) {
                    throw new \RuntimeException('Book not found for book_id=' . $r['book_id']);
                }

                $currentStock = (int)($bookRow['stock_quantity'] ?? 0);
                $qty = (int)($r['qty'] ?? 0);

                if ($currentStock < $qty) {
                    // insufficient stock -> rollback transaction by throwing
                    throw new \RuntimeException('Insufficient stock for book_id=' . $r['book_id']);
                }

                // now safe to decrement
                $d->prepareAndRun('UPDATE books SET stock_quantity = stock_quantity - :q WHERE id = :bid', [
                    ':q' => $qty,
                    ':bid' => $r['book_id']
                ]);
                            // mark reservation confirmed
                            $d->prepareAndRun('UPDATE inventory_reservations SET status = :st WHERE id = :id', [
                                ':st' => 'confirmed',
                                ':id' => $r['id']
                            ]);
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

                        // ensure $tokenHash is binary; store hex
                        $tokenHashHex = is_string($tokenHash) ? bin2hex($tokenHash) : bin2hex((string)$tokenHash);
                        $d->prepareAndRun('INSERT INTO order_item_downloads (order_id, book_id, asset_id, download_token_hash, encryption_key_version, token_key_version, max_uses, used, expires_at, created_at) VALUES (:oid, :bid, :aid, :hash, NULL, :tkv, :max, 0, :exp, NOW())', [
                            ':oid' => $orderId,
                            ':bid' => $a['book_id'],
                            ':aid' => $a['asset_id'],
                            ':hash' => $tokenHashHex,
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
        // cache-only: never call external gateway from here.
        try {
            // cache key MUST match wrapper/worker key scheme
            $statusCacheKey = 'gopay_status_' . substr(hash('sha256', $gopayPaymentId), 0, 32);

            // require PSR-16 cache instance on adapter as $this->cache
            if (!isset($this->cache) || !($this->cache instanceof \Psr\SimpleCache\CacheInterface)) {
                // no cache available -> log and return safe pseudo-state
                try {
                    if (isset($this->logger) && method_exists($this->logger, 'warning')) {
                        $this->logger->warning('fetchStatus: no cache instance available, returning pseudo CREATED', ['id' => $gopayPaymentId]);
                    }
                } catch (\Throwable $_) {}
                return [
                    'state' => 'CREATED',
                    '_pseudo' => true,
                    '_cached' => false,
                    '_message' => 'No cache instance available; returning pseudo CREATED.',
                ];
            }

            // Try to read cached status
            try {
                $cached = $this->cache->get($statusCacheKey);
            } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
                // invalid cache key — log & fallback to pseudo
                try {
                    if (isset($this->logger) && method_exists($this->logger, 'warning')) {
                        $this->logger->warning('fetchStatus: cache invalid argument', ['id' => $gopayPaymentId, 'exception' => (string)$e]);
                    }
                } catch (\Throwable $_) {}
                $cached = null;
            } catch (\Throwable $e) {
                // cache read error — log & fallback
                try {
                    if (isset($this->logger) && method_exists($this->logger, 'warning')) {
                        $this->logger->warning('fetchStatus: cache read failed', ['id' => $gopayPaymentId, 'exception' => (string)$e]);
                    } 
                } catch (\Throwable $_) {}
                $cached = null;
            }

            if (is_array($cached)) {
                // found cached gateway response -> return it (annotate)
                try {
                    if (isset($this->logger) && method_exists($this->logger, 'info')) {
                        $this->logger->info('fetchStatus: returning cached status', ['cache_key' => $statusCacheKey, 'id' => $gopayPaymentId]);
                    } elseif (class_exists('Logger')) {
                        Logger::info('fetchStatus: returning cached status', null, ['cache_key' => $statusCacheKey, 'id' => $gopayPaymentId]);
                    }
                } catch (\Throwable $_) {}
                $cached['_cached'] = true;
                return $cached;
            }

            // nothing in cache -> safe pseudo state (CREATED)
            try {
                if (isset($this->logger) && method_exists($this->logger, 'info')) {
                    $this->logger->info('fetchStatus: cache empty, returning pseudo CREATED', ['id' => $gopayPaymentId]);
                } elseif (class_exists('Logger')) {
                    Logger::info('fetchStatus: cache empty, returning pseudo CREATED', null, ['id' => $gopayPaymentId]);
                }
            } catch (\Throwable $_) {}

            return [
                'state' => 'CREATED',
                '_pseudo' => true,
                '_cached' => false,
                '_message' => 'No cached gateway status available yet.'
            ];
        } catch (\Throwable $e) {
            // defensive: log unexpected error but return pseudo-created
            try {
                if (isset($this->logger) && method_exists($this->logger, 'systemError')) {
                    $this->logger->systemError($e, null, null, ['phase' => 'adapter.fetchStatus', 'id' => $gopayPaymentId]);
                } elseif (class_exists('Logger')) {
                    Logger::systemError($e, null, null, ['phase' => 'adapter.fetchStatus', 'id' => $gopayPaymentId]);
                }
            } catch (\Throwable $_) {}
            return [
                'state' => 'CREATED',
                '_pseudo' => true,
                '_cached' => false,
                '_message' => 'Error reading cache; returning pseudo CREATED.'
            ];
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
    
    /**
     * Safe cache key builder (PSR-16 safe: žádné ":" atd.)
     */
    private function makeCacheKey(string $idempHash): string
    {
        // používáme md5 pro krátký, PSR-16-safe prefix bez zakázaných znaků
        return 'gopay_idemp_' . md5(($this->notificationUrl ?? '') . '|' . ($this->returnUrl ?? '') . '|' . $idempHash);
    }

    /**
     * Lookup idempotency (tries FileCache first, DB fallback).
     * Returns associative array with keys: payment_id, redirect_url, gopay, order_id (optional) or null.
     */
    public function lookupIdempotency(string $idempotencyKey): ?array
    {
        if (trim((string)$idempotencyKey) === '') {
            throw new \InvalidArgumentException('lookupIdempotency requires a non-empty idempotencyKey');
        }
        $idempHash = hash('sha256', (string)$idempotencyKey);
        $cacheKey = $this->makeCacheKey($idempHash);

        // 1) try FileCache
        if (!empty($this->cache) && method_exists($this->cache, 'get')) {
            try {
                $cached = $this->cache->get($cacheKey);
                if (is_array($cached) && !empty($cached['payment_id'])) {
                    // verify payment still exists
                    $p = $this->db->fetch('SELECT id, details FROM payments WHERE id = :id LIMIT 1', [':id' => $cached['payment_id']]);
                    if ($p !== null) {
                        // ensure redirect/gopay present
                        if (empty($cached['gopay']) && !empty($p['details'])) {
                            $cached['gopay'] = json_decode($p['details'], true) ?: null;
                        }
                        if (empty($cached['redirect_url']) && !empty($cached['gopay']) && is_array($cached['gopay'])) {
                            $g = $cached['gopay'];
                            if (isset($g[0]['gw_url'])) $cached['redirect_url'] = $g[0]['gw_url'];
                            $cached['redirect_url'] = $cached['redirect_url'] ?? ($g['gw_url'] ?? $g['payment_redirect'] ?? $g['redirect_url'] ?? null);
                        }
                        return $cached;
                    }
                    // stale cache -> try delete
                    if (method_exists($this->cache, 'delete')) {
                        try { $this->cache->delete($cacheKey); } catch (\Throwable $_) {}
                    }
                }
            } catch (\Throwable $_) {
                // cache read problems -> fallback to DB
            }
        }

        // 2) DB fallback
        try {
            $row = $this->db->fetch('SELECT payment_id FROM idempotency_keys WHERE key_hash = :k LIMIT 1', [':k' => $idempHash]);
            if (!empty($row['payment_id'])) {
                $p = $this->db->fetch('SELECT id, details, order_id FROM payments WHERE id = :id LIMIT 1', [':id' => $row['payment_id']]);
                if ($p !== null) {
                    $gopay = null;
                    if (!empty($p['details'])) {
                        $gopay = json_decode($p['details'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) $gopay = null;
                    }
                    $redirect = null;
                    if (is_array($gopay)) {
                        if (isset($gopay[0]['gw_url'])) $redirect = $gopay[0]['gw_url'];
                        $redirect = $redirect ?? ($gopay['gw_url'] ?? $gopay['payment_redirect'] ?? $gopay['redirect_url'] ?? null);
                    }
                    $out = [
                        'payment_id' => (int)$p['id'],
                        'redirect_url' => $redirect,
                        'gopay' => $gopay,
                        'order_id' => isset($p['order_id']) ? (int)$p['order_id'] : null
                    ];
                    // repopulate cache (best-effort)
                    if (!empty($this->cache) && method_exists($this->cache, 'set')) {
                        try { $this->cache->set($cacheKey, $out, 86400); } catch (\Throwable $_) {}
                    }
                    return $out;
                }
            }
        } catch (\Throwable $_) {
            // DB read failed -> miss
        }
        return null;
    }

    /**
     * Persist idempotency: write DB (best-effort) and set FileCache (best-effort).
     * $payload should be an array with payment_id, redirect_url, gopay, etc.
     */
    public function persistIdempotency(string $idempotencyKey, array $payload, int $paymentId): void
    {
        if (trim((string)$idempotencyKey) === '') {
            throw new \InvalidArgumentException('persistIdempotency requires a non-empty idempotencyKey');
        }
        $idempHash = hash('sha256', (string)$idempotencyKey);
        $cacheKey = $this->makeCacheKey($idempHash);

        // 1) DB upsert (best-effort)
        try {
            $this->db->prepareAndRun(
                'INSERT INTO idempotency_keys (key_hash, payment_id, ttl_seconds, created_at)
                VALUES (:k, :pid, :ttl, NOW())
                ON DUPLICATE KEY UPDATE payment_id = VALUES(payment_id), ttl_seconds = VALUES(ttl_seconds)',
                [':k' => $idempHash, ':pid' => $paymentId, ':ttl' => 86400]
            );
        } catch (\Throwable $e) {
            try { $this->logger->warn('persistIdempotency: DB write failed', null, ['exception' => (string)$e, 'key' => $idempHash]); } catch (\Throwable $_) {}
        }

        // 2) File cache (store array, not JSON)
        if (!empty($this->cache) && method_exists($this->cache, 'set')) {
            try { $this->cache->set($cacheKey, $payload, 86400); } catch (\Throwable $e) {
                try { $this->logger->warn('persistIdempotency: cache set failed', null, ['exception' => (string)$e, 'cacheKey' => $cacheKey]); } catch (\Throwable $_) {}
            }
        }
    }

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
        if (is_array($row) && isset($row['id'])) {
            return (int)$row['id'];
        }
        return null;
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
            if ($this->mailer !== null && method_exists($this->mailer, 'enqueue')) {
                $notifId = $this->mailer->enqueue($payloadArr);
            } else {
                // fallback to static
                $notifId = Mailer::enqueue($payloadArr);
            }
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