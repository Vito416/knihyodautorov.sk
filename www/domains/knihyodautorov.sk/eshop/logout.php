<?php
require __DIR__ . '/inc/bootstrap.php';
Auth::logout($db);
header('Location: index.php'); exit;