<?php
// /admin/exports.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

$type = $_GET['type'] ?? 'orders';
$format = $_GET['format'] ?? 'csv';

header('Content-Type: text/plain; charset=utf-8');

if ($type === 'orders') {
    $rows = $pdo->query("SELECT o.*, u.meno AS user, u.email FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['id','user','email','total_price','currency','status','payment_method','created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['user'],$r['email'],$r['total_price'],$r['currency'],$r['status'],$r['payment_method'],$r['created_at']]);
        }
        fclose($out);
        exit;
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders_export_' . date('Ymd_His') . '.xml"');
        $xml = new SimpleXMLElement('<orders/>');
        foreach ($rows as $r) {
            $o = $xml->addChild('order');
            foreach (['id','user','email','total_price','currency','status','payment_method','created_at'] as $k) {
                $o->addChild($k, htmlspecialchars((string)$r[$k], ENT_XML1 | ENT_COMPAT | ENT_SUBSTITUTE));
            }
        }
        echo $xml->asXML();
        exit;
    }
}

if ($type === 'invoices') {
    $rows = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="invoices_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['id','invoice_number','order_id','total','currency','tax_rate','variable_symbol','created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['invoice_number'],$r['order_id'],$r['total'],$r['currency'],$r['tax_rate'],$r['variable_symbol'],$r['created_at']]);
        }
        fclose($out);
        exit;
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="invoices_export_' . date('Ymd_His') . '.xml"');
        $xml = new SimpleXMLElement('<invoices/>');
        foreach ($rows as $r) {
            $o = $xml->addChild('invoice');
            foreach (['id','invoice_number','order_id','total','currency','tax_rate','variable_symbol','created_at'] as $k) {
                $o->addChild($k, htmlspecialchars((string)$r[$k], ENT_XML1 | ENT_COMPAT | ENT_SUBSTITUTE));
            }
        }
        echo $xml->asXML();
        exit;
    }
}

http_response_code(400);
echo "Unknown export type/format";
exit;