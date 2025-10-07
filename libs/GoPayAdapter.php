<?php
declare(strict_types=1);

require_once __DIR__ . '/GoPayStatus.php';
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
    private bool $allowCreate = false;

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
     * Handle GoPay notification by gateway transaction id.
     *
     * @param string $gwId Gateway payment id (transaction id)
     * @param ?bool $allowCreate When false (worker mode) DO NOT INSERT new payments, only update existing ones.
     */
    public function handleNotify(string $gwId, ?bool $allowCreate = null): array
    {
        $lastError = null;
        $allowCreate = $allowCreate ?? $this->allowCreate;
        $gwId = trim((string)$gwId);

        if ($gwId === '') {
            try { $this->logger->warn('Notify called without gateway id', null, ['gwId' => $gwId]); } catch (\Throwable $_) {}
            throw new \RuntimeException('Webhook missing gateway payment id');
        }
        Logger::info('Processing GoPay notify for gateway id: ' . $gwId);
        // Always verify via GoPay API (authoritative) and use cache when possible
        $status = null;
        $fromCache = false;
        $cacheKey = 'gopay_status_' . substr(hash('sha256', $gwId), 0, 32);

        try {
            // první pokus: nefiltrujeme lokální cache tady, protože wrapper vrací informaci o tom,
            // odkud status pochází (from_cache). wrapper sám používá stejný cacheKey.
            $resp = $this->gopayClient->getStatus($gwId);

            // podporujeme dvě varianty návratu: 
            // 1) ['status'=>..., 'from_cache'=>bool]  (preferovaná)
            // 2) raw status array (fallback)
            if (is_array($resp) && array_key_exists('status', $resp) && is_array($resp['status'])) {
                $status = $resp['status'];
                $fromCache = !empty($resp['from_cache']);
            } else {
                $status = $resp;
                $fromCache = false;
            }
        } catch (\Throwable $e) {
            $lastError = $e;
            // pokud getStatus selže, logujeme a propadneme (caller očekává výjimku)
            $this->logger->systemError($e, null, null, ['phase' => 'gopay.getStatus', 'gopay_id' => $gwId]);
        }

        $gwState = $status['state'] ?? null;
        $statusEnum = GoPayStatus::tryFrom($gwState); // vrací null pokud neplatný stav
        // Pokud byl status vrácen z cache a je NON-PERMANENT, smažeme cache a načteme fresh status.
        // Toto přesně respektuje tvou podmínku — cache je mazána JEN když to wrapper potvrdí.
        if ($fromCache && $statusEnum !== null && $statusEnum->isNonPermanent()) {
            try { $this->logger->info('Cached non-permanent status detected, refreshing from GoPay', null, ['gopay_id'=>$gwId, 'cache_key'=>$cacheKey, 'status'=>$status]); } catch (\Throwable $_) {}
            // pokus o smazání cache (best-effort)
            try {
                if (isset($this->cache) && $this->cache instanceof \Psr\SimpleCache\CacheInterface) {
                    $this->cache->delete($cacheKey);
                    try { $this->logger->info('Deleted non-permanent cached status and will refresh from GoPay', null, ['gopay_id'=>$gwId, 'cache_key'=>$cacheKey]); } catch (\Throwable $_) {}
                } else {
                    try { $this->logger->info('Wrapper returned from_cache but local cache instance missing - refreshing from GoPay anyway', null, ['gopay_id'=>$gwId]); } catch (\Throwable $_) {}
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                try { $this->logger->warn('Failed to delete status cache', null, ['cache_key'=>$cacheKey, 'exception'=>(string)$e]); } catch (\Throwable $_) {}
                // pokračujeme i přesto — pokusíme se načíst fresh status níže
            }

            // znovu načti stav z GoPay (opět tolerujeme obal i raw tvar)
            try {
                $resp2 = $this->gopayClient->getStatus($gwId);
                if (is_array($resp2) && array_key_exists('status', $resp2) && is_array($resp2['status'])) {
                    $status = $resp2['status'];
                    // zde už nás "from_cache" nezajímá (je fresh)
                    $fromCache = !empty($resp2['from_cache']);
                } else {
                    $status = $resp2;
                    $fromCache = false;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->systemError($e, null, null, ['phase' => 'gopay.getStatus.refresh', 'gopay_id' => $gwId]);
            }
        }
        Logger::info('GoPay status fetched for notify', null, ['gopay_id' => $gwId, 'from_cache' => $fromCache, 'status' => $status]);
        if ($status === null) {
            try {
                $status = $this->gopayClient->getStatus($gwId);
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->systemError($e, null, null, ['phase' => 'gopay.getStatus', 'gopay_id' => $gwId]);
            }
            if (isset($this->cache) && $this->cache instanceof \Psr\SimpleCache\CacheInterface) {
                $this->cache->set($cacheKey, $status, 3600);
            }
        }

        // compute payload hash from the authoritative status (used for dedupe) asdasdasdasdasd
        $payloadHash = hash('sha256', json_encode($status));

        // dedupe: pokud už někdo viděl stejný payload hash, ignoruj
        try {
            $exists = $this->db->fetch('SELECT id FROM payments WHERE webhook_payload_hash = :h LIMIT 1', [':h' => $payloadHash]);
        } catch (\Throwable $e) {
            $lastError = $e;
            // v případě DB problému logujeme a pokračujeme (nebo přerušíme dle potřeby)
            try { $this->logger->systemError($e, null, null, ['phase' => 'db.dedupe_check']); } catch (\Throwable $_) {}
        }
        if ($exists !== null) {
            try { $this->logger->info('Duplicate webhook ignored', null, ['hash' => $payloadHash, 'gopay_id' => $gwId]); } catch (\Throwable $_) {}
            if ($statusEnum?->isNonPermanent() === true) {
                    $action = 'delete'; // Worker smaže záznam, aby další pokus mohl proběhnout
                } else {
                    $action = 'done';   // Worker označí jako done, další pokusy se nebudou dělat
                }
            if (!empty($lastError)) {
                $action = 'fail';
            }
            return ['action'=> $action];
        }
        Logger::info('Processing GoPay notify for gateway id: ' . $gwId . ' with new payload hash: ' . $payloadHash);
        // mapování statusu
            $gwState = $status['state'] ?? null;
            $statusEnum = GoPayStatus::tryFrom($gwState);

            if ($statusEnum === null) {
                $this->logger->warn('Unhandled GoPay status', null, ['gw_state' => $gwState]);
            } else {
                switch ($statusEnum) {
                    case GoPayStatus::CREATED:
                        // Flow pro CREATED
                        break;

                    case GoPayStatus::PAYMENT_METHOD_CHOSEN:
                        // Flow pro PAYMENT_METHOD_CHOSEN
                        break;

                    case GoPayStatus::PAID:
                        // Flow pro PAID
                        break;

                    case GoPayStatus::AUTHORIZED:
                        // Flow pro AUTHORIZED
                        break;

                    case GoPayStatus::CANCELED:
                        // Flow pro CANCELED
                        break;

                    case GoPayStatus::TIMEOUTED:
                        // Flow pro TIMEOUTED
                        break;

                    case GoPayStatus::REFUNDED:
                        // Flow pro REFUNDED
                        break;

                    case GoPayStatus::PARTIALLY_REFUNDED:
                        // Flow pro PARTIALLY_REFUNDED
                        break;
                }
            }

        $action = 'done';

        // Non-permanent status vrácený z cache, dedupe ignoroval payload → umožníme další pokus
        if ($statusEnum !== null && $statusEnum->isNonPermanent()) {
            $action = 'delete'; // Worker smaže záznam, aby další pokus mohl proběhnout
        }

        // Pokud nějaký permanentní status, nebo dedupe ignoroval, ale status je permanentní → done
        elseif ($statusEnum !== null && !$statusEnum->isNonPermanent()) {
            $action = 'done';
        }

        // Pokud nějaký fail (např. API error nebo DB insert fail), zvýšit attempts → fail
        elseif (!empty($lastError)) {
            $action = 'fail';
        }
        return [
            'action'       => $action,
        ];
    }

    public function fetchStatus(string $gopayPaymentId): array
    {
        // cache-only: never call external gateway from here and never write to cache.
        $statusCacheKey = 'gopay_status_' . substr(hash('sha256', $gopayPaymentId), 0, 32);

        // Require PSR-16 cache instance
        if (!isset($this->cache) || !($this->cache instanceof \Psr\SimpleCache\CacheInterface)) {
            try {
                if (isset($this->logger) && method_exists($this->logger, 'warn')) {
                    $this->logger->warn('fetchStatus: no cache instance available, returning pseudo CREATED', null, ['id' => $gopayPaymentId]);
                } elseif (class_exists('Logger')) {
                    Logger::systemMessage('warning', 'fetchStatus: no cache instance available, returning pseudo CREATED', null, ['id' => $gopayPaymentId]);
                }
            } catch (\Throwable $_) {}
            return [
                'state' => 'CREATED',
                '_pseudo' => true,
                '_cached' => false,
                '_message' => 'No cache instance available; returning pseudo CREATED.',
            ];
        }

        // Try to read cached status (do NOT write to cache here)
        try {
            $cached = $this->cache->get($statusCacheKey);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            // invalid cache key — log & return pseudo (do not write or modify cache)
            try {
                if (isset($this->logger) && method_exists($this->logger, 'warn')) {
                    $this->logger->warn('fetchStatus: cache invalid argument', null, ['id' => $gopayPaymentId, 'exception' => (string)$e]);
                } elseif (class_exists('Logger')) {
                    Logger::systemMessage('warning', 'fetchStatus: cache invalid argument', null, ['id' => $gopayPaymentId, 'exception' => (string)$e]);
                }
            } catch (\Throwable $_) {}
            $cached = null;
        } catch (\Throwable $e) {
            // generic cache read error — log & fallback to pseudo (no writes)
            try {
                if (isset($this->logger) && method_exists($this->logger, 'warn')) {
                    $this->logger->warn('fetchStatus: cache read failed', null, ['id' => $gopayPaymentId, 'exception' => (string)$e]);
                } elseif (class_exists('Logger')) {
                    Logger::systemMessage('warning', 'fetchStatus: cache read failed', null, ['id' => $gopayPaymentId, 'exception' => (string)$e]);
                }
            } catch (\Throwable $_) {}
            $cached = null;
        }

        // If nothing cached -> pseudo CREATED
        if ($cached === null) {
            try {
                if (isset($this->logger) && method_exists($this->logger, 'info')) {
                    $this->logger->info('fetchStatus: cache empty, returning pseudo CREATED', null, ['id' => $gopayPaymentId]);
                } elseif (class_exists('Logger')) {
                    Logger::systemMessage('info', 'fetchStatus: cache empty, returning pseudo CREATED', null, ['id' => $gopayPaymentId]);
                }
            } catch (\Throwable $_) {}
            return [
                'state' => 'CREATED',
                '_pseudo' => true,
                '_cached' => false,
                '_message' => 'No cached gateway status available yet.'
            ];
        }

        // If cached value exists, perform basic validation (must be array and include 'state')
        if (!is_array($cached) || !array_key_exists('state', $cached)) {
            // treat as miss (do not mutate or write)
            try {
                if (isset($this->logger) && method_exists($this->logger, 'warn')) {
                    $this->logger->warn('fetchStatus: cached value invalid shape, treating as miss', null, ['id' => $gopayPaymentId]);
                } elseif (class_exists('Logger')) {
                    Logger::systemMessage('warning', 'fetchStatus: cached value invalid shape, treating as miss', null, ['id' => $gopayPaymentId]);
                }
            } catch (\Throwable $_) {}
            return [
                'state' => 'CREATED',
                '_pseudo' => true,
                '_cached' => false,
                '_message' => 'Cached value invalid or malformed; returning pseudo CREATED.'
            ];
        }

        // Return a defensive copy and annotate as cached (no writes)
        $out = $cached;
        $out['_cached'] = true;
        // ensure minimal canonical keys exist
        $out['state'] = (string)($out['state'] ?? 'CREATED');

        try {
            if (isset($this->logger) && method_exists($this->logger, 'info')) {
                $this->logger->info('fetchStatus: returning cached status', null, ['cache_key' => $statusCacheKey, 'id' => $gopayPaymentId]);
            } elseif (class_exists('Logger')) {
                Logger::systemMessage('info', 'fetchStatus: returning cached status', null, ['cache_key' => $statusCacheKey, 'id' => $gopayPaymentId]);
            }
        } catch (\Throwable $_) {}

        return $out;
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
}