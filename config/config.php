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
    
    // Maximum devices allowed per guest (room + surname combination)
    // This is a hard limit - guest cannot register more devices even if old ones disconnect
    'max_devices_per_guest' => 3,
    
    // [
    // // Database settings
    // 'host' => 'localhost',
    // 'database' => 'moa-wifi-management',
    // 'username' => 'mwm',
    // 'password' => 'bM2KbKiXgFesAXl24RcU',
    // 'charset' => 'utf8mb4',
    // 'options' => [
    //     PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    //     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    //     PDO::ATTR_EMULATE_PREPARES => false,
    // ],
    // MikroTik RouterOS API settings
    'mikrotik' => [
        'enabled' => true,
        'host' => '193.242.185.58',      // Change to your Router IP
        //'host' => '192.168.88.1',      // Change to your Router IP
        'user' => 'egh',           // Change to your API username
        'pass' => '?7JW3T-2NC-!UGnD',       // Change to your API password
        'port' => 8728,
        'profiles' => [
            'normal' => 'hm-10M',       // 10 Mbps profile
            'fast'   => 'hm-20M'        // 20 Mbps profile (Skip Cleaning)
        ]
    ]
];
?>