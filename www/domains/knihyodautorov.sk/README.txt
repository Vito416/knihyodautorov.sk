1) Skontroluj /db/config/config.php — musí vracať PDO. Príklad:

<?php
// db/config/config.php
$pdo = new PDO('mysql:host=localhost;dbname=dbname;charset=utf8mb4','dbuser','dbpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
return $pdo;

2) Skontroluj že /libs/autoload.php existuje a načíta všetky knižnice (mPDF a deps, phpqrcode, PHPMailer, Intervention,…). Máš už admin/debug/lib-test.php — použij ho.

3) Vytvor (ak neexistujú): /books-img/, /books-pdf/, /eshop/invoices/ a zabezpeč .htaccess (deny all) v /books-pdf/.

4) Skontroluj v DB settings hodnoty: company_name, company_iban, eshop_download_token_ttl a nastav ich.

5) Pridaj ornamenty: /assets/ornament-corner.png a /assets/seal.png a logo /assets/logoobdelnikbezpozadi.png.

6) Pre mPDF: ak chcete PDF generovanie, nahrajte kompletnú distribúciu mPDF (vrátane dependencies) do /libs/mpdf/ a zabezpeč libs/autoload.php správne. Ak nie, systém uloží HTML faktúru a admin môže generovať PDF manuálne.