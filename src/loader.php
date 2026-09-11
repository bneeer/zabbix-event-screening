<?php

declare(strict_types=1);

/*
 * Application bootstrap. Safe to require multiple times.
 *
 * 1. Composer autoload
 * 2. Optional .env file (never overrides real environment variables)
 * 3. Constants derived from the environment (config/settings.php)
 * 4. Runtime defaults (timezone, error reporting)
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

if (is_readable($projectRoot . '/.env')) {
    Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();
}

require $projectRoot . '/config/settings.php';

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'production' ? 'stderr' : '1');
date_default_timezone_set(APP_TIMEZONE);
