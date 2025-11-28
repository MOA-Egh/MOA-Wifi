<?php
/**
 * Database Configuration for MOA WiFi Management System
 * Update these settings according to your database setup
 */

return [
    'host' => 'localhost',
    'database' => 'moa_wifi_management',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
?>