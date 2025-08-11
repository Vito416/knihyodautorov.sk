<?php
// /admin/logout.php
require_once __DIR__ . '/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['admin_user_id']);
header('Location: login.php');
exit;
