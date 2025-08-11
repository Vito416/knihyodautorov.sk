<?php
// /admin/inc/helpers.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * HTML escape (unikátny názov aby sa nedeklaroval esc())
 */
function adm_esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Rýchly JSON helper
 */
function adm_json($data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Formátovanie sumy
 */
function adm_money($v): string {
    return number_format((float)$v, 2, ',', ' ') . ' €';
}
