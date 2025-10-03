<?php
// templates/pages/order_status.php
// Expects:
//  - $gopayId (string|null)
//  - $mapped (array) ['payment_status' => ..., 'order_status' => ...]
//  - $paymentRow (array|null) optional DB row (may contain order_id, amount, currency)
//  - $gatewayStatus (array|null) raw status from GoPay (will be printed for debugging/detail)
//  - $message (string|null) optional user-friendly message
//  - $order_url (string|null) optional direct link to order page
// Slovenské texty — uprav podľa potreby.
$pageTitle = $pageTitle ?? 'Stav platby';
$gopayId = isset($gopayId) ? (string)$gopayId : null;
$mapped = is_array($mapped) ? $mapped : ['payment_status' => 'unknown', 'order_status' => 'unknown'];
$paymentRow = is_array($paymentRow) ? $paymentRow : null;
$gatewayStatus = $gatewayStatus ?? null;
$message = isset($message) ? (string)$message : null;
$order_url = $order_url ?? null;
?>
<article class="order-status card">
    <header class="card-header">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <div class="meta">
            <?php if ($gopayId): ?>
                <span class="meta-pill">ID platby: <strong><?= htmlspecialchars($gopayId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
            <?php endif; ?>
            <span class="meta-pill">Stav platby: <strong><?= htmlspecialchars($mapped['payment_status'] ?? 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
            <span class="meta-pill">Stav objednávky: <strong><?= htmlspecialchars($mapped['order_status'] ?? 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
        </div>
    </header>

    <div class="card-body">
        <?php if ($message): ?>
            <div class="notice notice-info" role="status"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="info-block" style="margin-top:0.75rem">
            <p>
                Poznámka: Košík sa **automaticky nevymaže** - bude vymazaný až po potvrdení objednávky. Nemusíte ho mazať manuálne.
                Týmto sa snažíme zabezpečiť, aby ste o váš výber neprichádzali v prípade dočasného zlyhania platby.
            </p>
            <p>
                Objednávka, ktorá nebude uhradená do <strong>7 dní</strong>, bude automaticky stornovaná.
            </p>
        </section>

        <?php if ($paymentRow !== null): ?>
            <section class="payment-summary" style="margin-top:1rem">
                <h2 class="section-title">Informácie o platbe</h2>
                <dl class="dl-horizontal">
                    <?php if (!empty($paymentRow['order_id'])): ?>
                        <dt>Objednávka</dt>
                        <dd>
                            <?= htmlspecialchars((string)$paymentRow['order_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            <?php if ($order_url): ?>
                                — <a href="<?= htmlspecialchars($order_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Zobraziť objednávku</a>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (isset($paymentRow['amount'])): ?>
                        <dt>Suma</dt>
                        <dd><?= number_format((float)$paymentRow['amount'], 2, ',', ' ') ?> <?= htmlspecialchars($paymentRow['currency'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                    <?php endif; ?>

                    <dt>Platobná brána</dt>
                    <dd><?= htmlspecialchars($paymentRow['gateway'] ?? 'gopay', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>

                    <dt>Transakčné ID</dt>
                    <dd><?= htmlspecialchars($paymentRow['transaction_id'] ?? $gopayId ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </dl>
            </section>
        <?php else: ?>
            <section class="payment-summary" style="margin-top:1rem">
                <h2 class="section-title">Informácie o platbe</h2>
                <p class="muted">Záznam o platbe ešte nemusí byť v systéme. Ak ste platbu dokončili, skontrolujte túto stránku o niekoľko sekúnd.</p>
            </section>
        <?php endif; ?>

        <section class="pdf-note" style="margin-top:1rem">
            <h3 class="section-title">Dôležité — PDF knihy a vrátenie peňazí</h3>
            <p>
                Za zakúpené PDF knihy nemôžeme vrátiť peniaze po tom, čo si súbor stiahnete alebo otvoríte cez odkaz v e-maile.
                Ak ste sa omylom rozhodli kúpiť, <strong>neklikajte</strong> na odkaz na stiahnutie v zaslanom e-maile.
                Namiesto toho použite v tom istom e-maile tlačidlo pre vrátenie (refund) alebo kontaktujte podporu — až potom spracujeme vrátenie peňazí podľa našich pravidiel.
            </p>
        </section>

        <section class="actions" style="margin-top:1.25rem">
            <!-- Jediné tlačidlo: späť do e-shopu -->
            <a class="btn btn-primary" href="/eshop">Späť do e-shopu</a>
        </section>

        <section class="gateway-detail" style="margin-top:1.25rem">
            <h3 class="section-title">Detail odpovede brány (pre administráciu)</h3>
            <?php if ($gatewayStatus === null): ?>
                <p class="muted">Žiadne ďalšie informácie.</p>
            <?php else: ?>
                <pre class="debug-pre" style="white-space:pre-wrap;word-break:break-word;background:#f8f8f8;padding:12px;border-radius:6px;border:1px solid #e6e6e6;font-size:0.9rem;">
<?= htmlspecialchars(json_encode($gatewayStatus, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </pre>
            <?php endif; ?>
        </section>
    </div>
</article>