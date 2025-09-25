<?php
declare(strict_types=1);

/**
 * inc/config_loader.php
 *
 * Jednoduchý loader konfigurace — obal nad secure/config.php
 * Vrací asociativní $config array nebo vyhodí exception.
 */

function load_project_config(string $projectRoot): array
{
    $configFile = rtrim($projectRoot, '/\\') . '/secure/config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException('Missing secure/config.php at ' . $configFile);
    }

    /** @noinspection PhpIncludeInspection */
    require $configFile;

    if (!isset($config) || !is_array($config)) {
        throw new RuntimeException('secure/config.php must define $config array');
    }

    // bezpečnostní sanity checks (základ)
    if (empty($config['paths']['keys'])) {
        throw new RuntimeException('config.paths.keys is required in secure/config.php');
    }
    if (empty($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException('config.db is required in secure/config.php');
    }

    return $config;
}