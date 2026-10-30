<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$envFile = dirname(__DIR__).'/.env';
if (is_file($envFile)) {
    (new Dotenv())->bootEnv($envFile);
}
