<?php

/* ------------------------------------------------------------------ */
// File: libs/GoPaySdkWrapper.php
// -----------------------------
declare(strict_types=1);

// Thin wrapper around the official gopay-php-api. Keep it minimal so adapter
// code can rely on a stable interface (PaymentGatewayInterface).
final class GoPaySdkWrapper implements PaymentGatewayInterface
{
    private $client; // underlying GoPay client returned by \GoPay\payments()
    private array $cfg;

    /**
     * $cfg expected keys:
     *  - goid
     *  - clientId
     *  - clientSecret
     *  - gatewayUrl
     *  - language (optional, e.g. \GoPay\Definition\Language::CZECH)
     *  - scope (optional, e.g. \GoPay\Definition\TokenScope::ALL)
     */
    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;

        // make sure SDK autoloader is available; silent include if not
        if (!function_exists('GoPay\\payments')) {
            @include_once __DIR__ . '/../vendor/autoload.php';
        }

        // create SDK client; the helper returns an object exposing createPayment/getStatus/refundPayment
        $this->client = \GoPay\payments([
            'goid' => $cfg['goid'] ?? null,
            'clientId' => $cfg['clientId'] ?? null,
            'clientSecret' => $cfg['clientSecret'] ?? null,
            'gatewayUrl' => $cfg['gatewayUrl'] ?? null,
            // language/scope may be passed through if present
            'language' => $cfg['language'] ?? null,
            'scope' => $cfg['scope'] ?? null,
        ]);
    }

    public function createPayment(array $payload)
    {
        try {
            if (class_exists('Logger')) {
                try { Logger::info('gopay.wrapper.createPayment', null, ['order_number' => $payload['order_number'] ?? null]); } catch (\Throwable $_) {}
            }
            return $this->client->createPayment($payload);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                try { Logger::systemError($e, null, null, ['phase' => 'gopay.createPayment']); } catch (\Throwable $_) {}
            }
            throw $e;
        }
    }

    public function getStatus(string $gatewayPaymentId)
    {
        try {
            if (class_exists('Logger')) {
                try { Logger::info('gopay.wrapper.getStatus', null, ['gopay_id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            }
            return $this->client->getStatus($gatewayPaymentId);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                try { Logger::systemError($e, null, null, ['phase' => 'gopay.getStatus', 'gopay_id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            }
            throw $e;
        }
    }

    public function refundPayment(string $gatewayPaymentId, array $args)
    {
        try {
            if (class_exists('Logger')) {
                try { Logger::info('gopay.wrapper.refundPayment', null, ['gopay_id' => $gatewayPaymentId, 'args' => $args]); } catch (\Throwable $_) {}
            }
            return $this->client->refundPayment($gatewayPaymentId, $args);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                try { Logger::systemError($e, null, null, ['phase' => 'gopay.refundPayment', 'gopay_id' => $gatewayPaymentId]); } catch (\Throwable $_) {}
            }
            throw $e;
        }
    }
}