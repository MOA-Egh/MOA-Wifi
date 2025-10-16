<?php
/**
 * PMS API Configuration Template
 * Copy this to pms_config.php and update with your actual PMS API details
 */

return [
    // Your Property Management System API configuration
    'pms_api' => [
        'base_url' => 'https://your-pms-system.com/api/v1',  // Replace with your PMS API URL
        'api_key' => 'your_pms_api_key_here',                // Replace with your PMS API key
        'timeout' => 10,                                     // API request timeout in seconds
        
        // API endpoints - adjust according to your PMS API structure
        'endpoints' => [
            'validate_guest' => '/reservations/validate',     // Endpoint to validate guest credentials
            'current_reservations' => '/reservations/current' // Endpoint to get current reservations
        ]
    ],
    
    // Fallback settings for development/testing
    'development' => [
        'use_fallback' => true,  // Set to false in production
        'test_reservations' => [
            '101' => ['Schmidt', 'Mueller'],
            '102' => ['Weber', 'Fischer'], 
            '103' => ['Becker', 'Wagner'],
            '201' => ['Schulz', 'Hoffmann'],
            '202' => ['Koch', 'Richter'],
            '203' => ['Neumann', 'Klein'],
            '301' => ['Wolf', 'Schroeder'],
            '302' => ['Zimmermann', 'Braun'],
            '303' => ['Krueger', 'Hofmann'],
            '401' => ['Hartmann', 'Lange']
        ]
    ]
];

/*
Expected PMS API Response Format:

POST /reservations/validate
{
    "room_number": "101",
    "guest_surname": "Schmidt", 
    "date": "2025-10-16"
}

Response:
{
    "valid": true,
    "room_number": "101",
    "guest_surname": "Schmidt",
    "check_in": "2025-10-15",
    "check_out": "2025-10-18", 
    "reservation_id": "RES123456"
}

GET /reservations/current?date=2025-10-16
Response:
{
    "reservations": [
        {
            "room_number": "101",
            "guest_surname": "Schmidt",
            "check_in": "2025-10-15",
            "check_out": "2025-10-18"
        }
    ]
}
*/
?>