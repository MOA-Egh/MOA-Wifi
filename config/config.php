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
    
    // [
    // Database settings
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
        'user' => 'hm-wifi-api',           // Change to your API username
        'pass' => 'r5J2byi8bZa5#W',       // Change to your API password
        'port' => 8728,
        // Maximum concurrent sessions per user (devices connected at the same time)
        // This is enforced by checking MikroTik active sessions before allowing login
        'max_sessions' => 3,
        // Default profile for hotspot users (should have NO rate limit - queues handle that)
        'default_profile' => 'hm-hotspot',
        // Speed limits for Simple Queues (format: upload/download in bits per second)
        // These are applied per-device via Simple Queues, not via user profiles
        'speed_limits' => [
            'normal' => '10M/10M',    // 10 Mbps up/down
            'fast'   => '20M/20M'     // 20 Mbps up/down
        ],
        // Legacy profile mapping (kept for reference, not used for rate limiting anymore)
        'profiles' => [
            'normal' => 'hm-10M',       // 10 Mbps profile
            'fast'   => 'hm-20M'        // 20 Mbps profile (Skip Cleaning)
        ]
    ]
];
?>