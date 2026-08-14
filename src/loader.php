<?php
ini_set('error_reporting', E_ALL);
date_default_timezone_set('America/Sao_Paulo');

require __DIR__ .'/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ .'/../');
$dotenv->load();

require __DIR__ .'/../config/settings.php';
