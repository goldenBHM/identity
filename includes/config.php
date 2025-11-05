<?php

require __DIR__ . '/ErrorNotifier.php';

$dbBrightOffers = new mysqli($env['DB_BRIGHTOFFERS_ENDPOINT'], $env['DB_BRIGHTOFFERS_USER'], $env['DB_BRIGHTOFFERS_PASSWORD'], $env['DB_BRIGHTOFFERS_DATABASE']);

$notifier = new ErrorNotifier([
    'mysqli' => $dbBrightOffers,
    'slack_webhook' => $env['SLACK_ERROR_WEBHOOK'],
    'env' => $env['ENVIRONMENT'],
    'app' => 'fingerprint-php',
    'throttle' => 600, // 10 min cooldown per unique error
]);

$notifier->register();
