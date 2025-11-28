<?php
/**
 * Mews PMS Configuration for MOA Hotel WiFi System
 * 
 * This file contains configuration settings for the Mews integration
 */

return [
    // Mews Environment Configuration
    'mews' => [
        'environment' => 'demo', // Options: 'demo', 'cert', 'prod'
        
        // Environment descriptions:
        // 'demo'  - Mews demo environment for testing
        // 'cert'  - Mews certification environment 
        // 'prod'  - Production Mews environment
        
        'timeout' => 30, // API request timeout in seconds
        
        // INI file paths for different environments
        'ini_paths' => [
            'demo' => __DIR__ . '/ini/demo_mews.ini',
            'cert' => __DIR__ . '/ini/cert_mews.ini', 
            'prod' => __DIR__ . '/ini/prod_mews.ini'
        ],
        
        // Service IDs for your hotel
        'service_ids' => [
            '4444f78b-ca4f-4802-be17-acc501177efe', // Stay Service
            'af7a7d60-c9ba-402c-a761-b1b700fb3106', // DayUse Service
            '63952eb6-5908-4b66-9955-acd100d550d6'  // Employee Rooms Service
        ],
        
        // API endpoints configuration
        'api_urls' => [
            'demo' => 'https://api.mews-demo.com/api/connector/v1',
            'cert' => 'https://api.mews-demo.com/api/connector/v1',
            'prod' => 'https://api.mews.com/api/connector/v1'
        ]
    ],
    
    // WiFi System Settings
    'wifi' => [
        'max_fast_devices_per_room' => 3,
        'default_session_timeout' => 86400, // 24 hours in seconds
        'require_cleaning_skip_for_fast' => true
    ],
    
    // Fallback settings for development/testing
    'development' => [
        'use_fallback_when_api_fails' => true,
        'log_api_errors' => true,
        'debug_mode' => false
    ]
];

/*
INI File Format (create these files manually):

For demo environment (/ini/demo_mews.ini):
```ini
ClientToken = "your_demo_client_token"
AccessToken = "your_demo_access_token"
Client = "your_demo_client_id"
EnterpriseId = "your_demo_enterprise_id"
```

For production environment (/ini/prod_mews.ini):
```ini
ClientToken = "your_prod_client_token"
AccessToken = "your_prod_access_token"
Client = "your_prod_client_id"
EnterpriseId = "your_prod_enterprise_id"
```

Make sure to keep these INI files secure and outside your web root!
*/
?>