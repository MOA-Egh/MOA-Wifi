<?php
/**
 * Database Configuration for MOA WiFi Management System
 * Update these settings according to your database setup
 */

return [
    // Database settings
    'host' => 'localhost',
    'database' => 'moa_wifi_management',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
    
    // MikroTik RouterOS API settings
    'mikrotik' => [
        'enabled' => true,
        'host' => '192.168.88.1',      // Change to your Router IP
        'user' => 'api_user',           // Change to your API username
        'pass' => 'api_password',       // Change to your API password
        'port' => 8728,
        'profiles' => [
            'normal' => 'hm_10M',       // 10 Mbps profile
            'fast'   => 'hm_20M'        // 20 Mbps profile (Skip Cleaning)
        ]
    ]
];
?>