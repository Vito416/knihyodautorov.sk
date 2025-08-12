<?php
// admin/test-mpdf.php
declare(strict_types=1);
require_once __DIR__ . '/../libs/autoload.php';
require_once __DIR__ . '/../libs/qr_helper.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$ok = [];

$ok['mpdf_class'] = class_exists('\Mpdf\Mpdf');

if (!$ok['mpdf_class']) {
    echo "<h2>mPDF nie je dostupný (class not found)</h2>";
    echo "<p>Skontroluj /libs/mpdf a /libs/autoload.php</p>";
    var_export($ok);
    exit;
}

try {
    $tmpDir = __DIR__ . '/tmp';
    @mkdir($tmpDir, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmpDir, 'mode'=>'utf-8']);
    $qr = libs_qr_png_data_uri('https://knihyodautorov.sk - test QR', 4, 1);
    $html = '<div style="font-family:Arial"><h1 style="color:#3e2a12">Test mPDF</h1>';
    $html .= '<p>Maličký PDF obsah s QR (Base64 PNG)</p>';
    if ($qr) $html .= '<img style="width:120px;height:auto" src="'.esc($qr).'" alt="QR"/>';
    $html .= '</div>';
    $mpdf->WriteHTML($html);
    $out = $tmpDir . '/test-mpdf-output.pdf';
    $mpdf->Output($out, \Mpdf\Output\Destination::FILE);
    if (file_exists($out)) {
        echo "<h2>PDF vygenerované: " . esc($out) . "</h2>";
        echo "<p><a href='/admin/tmp/test-mpdf-output.pdf' target='_blank'>Otvoriť PDF</a></p>";
    } else {
        echo "<h2>PDF sa nepodarilo zapísať</h2>";
    }
} catch (\Throwable $e) {
    echo "<h2>Chyba pri mPDF:</h2>";
    echo "<pre>" . esc($e->getMessage()) . "</pre>";
    if (method_exists($e, 'getTraceAsString')) {
        echo "<pre>" . esc($e->getTraceAsString()) . "</pre>";
    }
}