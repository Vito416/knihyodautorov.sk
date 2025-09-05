<?php
// libs/PayBySquare.php - generate simple PayBySquare-like payload (stub)
// Real PayBySquare format is more complex; here we produce QR text with amount, vs, message.
class PayBySquare {
    public static function generatePayload(array $invoice): string {
        // invoice expects: total, currency, invoice_number, due_date
        $amount = number_format($invoice['total'] ?? 0, 2, '.', '');
        $vs = $invoice['variable_symbol'] ?? ($invoice['invoice_number'] ?? time());
        $payload = "PAYSQUARE|AMOUNT={$amount}|CURRENCY={$invoice['currency']}|VS={$vs}|MSG=Invoice%20{$invoice['invoice_number']}";
        return $payload;
    }

    public static function qrImageUrl(string $payload, $size = 200): string {
        return 'https://quickchart.io/qr?text='.rawurlencode($payload).'&size='.intval($size);
    }
}