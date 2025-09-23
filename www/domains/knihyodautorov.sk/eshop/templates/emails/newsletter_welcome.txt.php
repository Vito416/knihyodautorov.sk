<?php
// newsletter_welcome.txt.php
// Plain text fallback pre e-mail

?>
Vitajte medzi odberateľmi noviniek

Ďakujeme, že ste si prihlásili odber našich noviniek.
Odteraz budete pravidelne dostávať informácie o nových knihách,
akciách a zaujímavostiach priamo do svojej e-mailovej schránky.

Sme radi, že ste s nami — vitajte v našej čitateľskej komunite.

--
Tento e-mail bol odoslaný automaticky, prosím neodpovedajte naň.
Ak si prajete zrušiť odber, nájdete odkaz na odhlásenie v každom ďalšom e-maile.

© <?= date('Y') ?> <?= htmlspecialchars($_ENV['APP_NAME'] ?? 'Naša služba', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>