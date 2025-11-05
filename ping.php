<?php

require_once __DIR__ . '/vendor/autoload.php';
$env = parse_ini_file(__DIR__ . '/.env');

$MONGO_URL = $env['MONGO_URL'];


// Create a new client and connect to the server
$client = new MongoDB\Client($MONGO_URL);

try {
    // Send a ping to confirm a successful connection
    $client->selectDatabase('admin')->command(['ping' => 1]);
    echo "Pinged your deployment. You successfully connected to MongoDB!\n";
} catch (Exception $e) {
    printf($e->getMessage());
}
