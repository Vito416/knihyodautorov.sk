<?php
// /libs/qr_helper.php
declare(strict_types=1);

/**
 * Vracia data-uri PNG obrázku QR (base64).
 * Vyžaduje phpqrcode (QRcode::png).
 *
 * @param string $text
 * @param int $size
 * @param int $margin
 * @return string|null data:image/png;base64,... alebo null ak nie je možné vygenerovať
 */
function qr_png_datauri(string $text, int $size = 4, int $margin = 2): ?string {
    // pokúsime sa načítať phpqrcode (ak ešte nie je)
    if (!class_exists('QRcode')) {
        $candidate = __DIR__ . '/phpqrcode/phpqrcode.php';
        if (file_exists($candidate)) require_once $candidate;
    }
    if (!class_exists('QRcode')) return null;

    ob_start();
    // QRcode::png($text, $outfile=false, $level=QR_ECLEVEL_L, $size=3, $margin=4)
    try {
        QRcode::png($text, false, QR_ECLEVEL_L, $size, $margin);
    } catch (Throwable $e) {
        ob_end_clean();
        return null;
    }
    $png = ob_get_clean();
    if ($png === '' || $png === false) return null;
    return 'data:image/png;base64,' . base64_encode($png);
}