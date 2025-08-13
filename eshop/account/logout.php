<?php
declare(strict_types=1);
/**
 * /eshop/account/logout.php
 */

require_once __DIR__ . '/../_init.php';
auth_logout();
flash_set('success','Boli ste odhlásení.');
redirect('/eshop/');