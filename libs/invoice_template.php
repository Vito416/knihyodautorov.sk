<?php
// /admin/lib/invoice_template.php
declare(strict_types=1);

/**
 * render_invoice_html(array $meta) : string
 * $meta keys: invoice_number, created_at, company (array), client (array), items (array of arrays with name, qty, unit_price),
 * subtotal, tax_rate, tax, total, variable_symbol, qr_datauri (nullable), stamp_img (optional datauri)
 */
function render_invoice_html(array $meta): string {
    $company = $meta['company'] ?? [];
    $client = $meta['client'] ?? [];
    $items = $meta['items'] ?? [];
    $invoiceNumber = htmlspecialchars($meta['invoice_number'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE);
    $createdAt = htmlspecialchars($meta['created_at'] ?? date('Y-m-d H:i'), ENT_QUOTES | ENT_SUBSTITUTE);
    $subtotal = number_format((float)($meta['subtotal'] ?? 0.0), 2, '.', '');
    $taxRate = number_format((float)($meta['tax_rate'] ?? 0), 2, '.', '');
    $tax = number_format((float)($meta['tax'] ?? 0.0), 2, '.', '');
    $total = number_format((float)($meta['total'] ?? 0.0), 2, '.', '');
    $currency = htmlspecialchars($meta['currency'] ?? 'EUR', ENT_QUOTES | ENT_SUBSTITUTE);
    $variableSymbol = htmlspecialchars($meta['variable_symbol'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE);
    $qr = $meta['qr_datauri'] ?? null;
    $stamp = $meta['stamp_datauri'] ?? null;

    // inline CSS — prispôsobené pre mPDF (používame základné fonty DejaVu Sans)
    $css = <<<CSS
    body{font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#2f2417; background:#fff; margin:0; padding:0;}
    .page{padding:28px; max-width:880px; margin:0 auto; background: linear-gradient(180deg,#fffdf8,#fbf3e3);}
    .header{display:flex;justify-content:space-between;align-items:flex-start;padding:18px;border-radius:6px 6px 0 0}
    .company{font-weight:900;color:#3e2a12}
    .meta{font-size:12px;color:#6b4e36}
    .bill-to{background: linear-gradient(90deg, rgba(207,152,58,0.06), rgba(255,255,255,0)); padding:14px;border-left:6px solid #c08a2e;border-radius:6px}
    table.items{width:100%;border-collapse:collapse;margin-top:12px}
    table.items th{background:#f7ead0;padding:10px;text-align:left;color:#3e2a12;font-weight:800;border-bottom:2px solid rgba(0,0,0,0.06)}
    table.items td{padding:10px;border-bottom:1px dashed rgba(0,0,0,0.06)}
    .totals{margin-top:12px;display:flex;justify-content:flex-end}
    .totals .box{background:linear-gradient(180deg,#fff8e6,#f2e0a7);padding:12px;border-radius:6px;font-weight:800}
    .qr{margin-top:10px}
    .stamp{position:absolute;right:54px;top:160px;opacity:0.9;transform:rotate(-8deg)}
    .seal{display:inline-block;padding:10px 14px;border-radius:999px;background:linear-gradient(180deg,#fff5d9,#ffd86b);border:4px solid rgba(192,138,46,0.9);box-shadow:0 6px 16px rgba(0,0,0,0.18)}
    .foot{margin-top:18px;color:#6b4e36;font-size:12px}
CSS;

    // HTML
    $html = "<!doctype html><html lang='sk'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'/><style>$css</style></head><body>";
    $html .= "<div class='page'>";

    // header
    $html .= "<div class='header'>";
    $html .= "<div><div class='company'>".htmlspecialchars($company['name'] ?? 'Knihy od autorov', ENT_QUOTES | ENT_SUBSTITUTE)."</div>";
    $html .= "<div class='meta'>".nl2br(htmlspecialchars($company['address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE))."</div></div>";
    $html .= "<div style='text-align:right'><div class='seal'>Faktúra</div><div class='meta' style='margin-top:8px'>Číslo: $invoiceNumber<br>Vystavené: $createdAt</div></div>";
    $html .= "</div>";

    // bill to + recipient
    $html .= "<div style='display:flex;gap:12px;margin-top:12px;position:relative'>";
    $html .= "<div style='flex:1'><div class='bill-to'><strong>Príjemca</strong><div style='margin-top:6px'>".nl2br(htmlspecialchars($client['name'] ?? '','ENT_QUOTES|ENT_SUBSTITUTE'))."</div><div class='meta' style='margin-top:6px'>".nl2br(htmlspecialchars($client['address'] ?? '','ENT_QUOTES|ENT_SUBSTITUTE'))."</div></div></div>";
    $html .= "<div style='width:180px;text-align:center'>";
    if ($stamp) {
        $html .= "<img class='stamp' src='$stamp' alt='Pečať' style='width:120px;height:120px;object-fit:contain' />";
    }
    if ($qr) {
        $html .= "<div class='qr'><img src='$qr' alt='QR' style='width:120px;height:120px;object-fit:contain;border-radius:6px' /></div>";
    }
    $html .= "</div></div>";

    // items
    $html .= "<table class='items'><thead><tr><th>Položka</th><th style='width:90px;text-align:center'>Množ.</th><th style='width:120px;text-align:right'>Jedn. cena</th><th style='width:120px;text-align:right'>Spolu</th></tr></thead><tbody>";
    foreach ($items as $it) {
        $name = htmlspecialchars($it['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE);
        $qty = (int)($it['qty'] ?? 1);
        $up = number_format((float)($it['unit_price'] ?? 0.0), 2, '.', '');
        $line = number_format($qty * (float)($it['unit_price'] ?? 0.0), 2, '.', '');
        $html .= "<tr><td>$name</td><td style='text-align:center'>$qty</td><td style='text-align:right'>$up $currency</td><td style='text-align:right'>$line $currency</td></tr>";
    }
    $html .= "</tbody></table>";

    // totals
    $html .= "<div class='totals'><div class='box'>Medzisúčet: $subtotal $currency<br>DPH ($taxRate%): $tax $currency<br><hr style='border:none;border-top:1px solid rgba(0,0,0,0.06);margin:8px 0'><div style='font-size:1.1rem'>Celkom: $total $currency</div></div></div>";

    $html .= "<div class='foot'>Variabilný symbol: <strong>$variableSymbol</strong><div style='margin-top:8px'>Ďakujeme za dôveru. Časť výťažku ide na babyboxy.</div></div>";

    $html .= "</div></body></html>";
    return $html;
}