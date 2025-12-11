<?php
/**
 * Mews API Full Test Script
 * 
 * This script demonstrates the full Mews API workflow:
 * 1. Fetch all resources (rooms)
 * 2. Fetch all reservations for today
 * 3. Match reservations to resources via AssignedResourceId
 * 4. Fetch customer details for each reservation via AccountId
 * 5. Display combined data
 * 6. Update random room states to 'Inspected'
 * 
 * Access via: http://localhost/MOA-Wifi/public/test_mews_full.php
 */

// Set content type for clean output
header('Content-Type: text/plain; charset=utf-8');

echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    MEWS API FULL TEST SCRIPT                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// STEP 1: Load dependencies and initialize
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 1: Initializing Mews Connector...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

require_once __DIR__ . '/../src/MewsConnector.php';
$mews_config = require __DIR__ . '/../config/mews_config.php';

$environment = 'cert';
echo "  Environment: $environment\n";

try {
    $mews = new MewsConnector($environment);
    echo "  ✓ MewsConnector initialized\n";
    echo "  API URL: " . $mews->getApiUrl() . "\n\n";
} catch (Exception $e) {
    echo "  ✗ Failed to initialize: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================================================
// STEP 2: Fetch all resources (rooms)
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 2: Fetching all resources from Mews...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Small delay to avoid rate limiting
usleep(500000); // 0.5 second

$resources = [];
$resourcesById = [];

try {
    $startTime = microtime(true);
    $response = $mews->getResources();
    $duration = round((microtime(true) - $startTime) * 1000);
    
    echo "  API call: GET /resources/getAll ({$duration}ms)\n";
    
    if (isset($response['Resources'])) {
        $resources = $response['Resources'];
        echo "  ✓ Found " . count($resources) . " resources\n\n";
        
        // Build lookup map by ID
        foreach ($resources as $resource) {
            $resourcesById[$resource['Id']] = $resource;
        }
        
        // Display resources table
        echo "  ┌──────────────────────────────────────┬────────────┬──────────────┐\n";
        echo "  │ Resource ID                          │ Name       │ State        │\n";
        echo "  ├──────────────────────────────────────┼────────────┼──────────────┤\n";
        
        foreach ($resources as $resource) {
            printf("  │ %-36s │ %-10s │ %-12s │\n",
                $resource['Id'],
                substr($resource['Name'] ?? 'N/A', 0, 10),
                substr($resource['State'] ?? 'N/A', 0, 12)
            );
        }
        echo "  └──────────────────────────────────────┴────────────┴──────────────┘\n";
    } else {
        echo "  ✗ No resources found in response\n";
        echo "  Response keys: " . implode(', ', array_keys($response)) . "\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// STEP 3: Fetch today's reservations
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 3: Fetching today's reservations from Mews...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Small delay to avoid rate limiting
usleep(500000); // 0.5 second

$reservations = [];
$accountIds = [];

try {
    $startOfDay = date('Y-m-d\T00:00:00\Z');
    $endOfDay = date('Y-m-d\T23:59:59\Z');
    
    $params = [
        'CollidingUtc' => [
            'StartUtc' => $startOfDay,
            'EndUtc' => $endOfDay
        ],
        'States' => ['Confirmed', 'Started']
    ];
    
    echo "  Date range: $startOfDay to $endOfDay\n";
    echo "  States: Confirmed, Started\n";
    
    $startTime = microtime(true);
    $response = $mews->getReservations($params);
    $duration = round((microtime(true) - $startTime) * 1000);
    
    echo "  API call: POST /reservations/getAll/2023-06-06 ({$duration}ms)\n";
    
    if (isset($response['Reservations'])) {
        $reservations = $response['Reservations'];
        echo "  ✓ Found " . count($reservations) . " reservations\n\n";
        
        // Collect unique AccountIds for customer lookup
        foreach ($reservations as $res) {
            if (isset($res['AccountId']) && $res['AccountId']) {
                $accountIds[$res['AccountId']] = true;
            }
        }
        echo "  Unique customers (AccountIds): " . count($accountIds) . "\n\n";
        
        // Display reservations table
        echo "  ┌──────────────────────────────────────┬──────────────────────────────────────┬────────────┬────────────┐\n";
        echo "  │ Reservation ID                       │ Assigned Resource ID                 │ Start      │ End        │\n";
        echo "  ├──────────────────────────────────────┼──────────────────────────────────────┼────────────┼────────────┤\n";
        
        $displayCount = min(count($reservations), 15);
        for ($i = 0; $i < $displayCount; $i++) {
            $res = $reservations[$i];
            printf("  │ %-36s │ %-36s │ %-10s │ %-10s │\n",
                $res['Id'],
                $res['AssignedResourceId'] ?? 'N/A',
                date('Y-m-d', strtotime($res['StartUtc'])),
                date('Y-m-d', strtotime($res['EndUtc']))
            );
        }
        if (count($reservations) > 15) {
            echo "  │ ... and " . (count($reservations) - 15) . " more reservations                                                                    │\n";
        }
        echo "  └──────────────────────────────────────┴──────────────────────────────────────┴────────────┴────────────┘\n";
    } else {
        echo "  ✗ No reservations found\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// STEP 4: Fetch customer details
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 4: Fetching customer details for reservations...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Small delay to avoid rate limiting
usleep(500000); // 0.5 second

$customers = [];
$customersById = [];

if (count($accountIds) > 0) {
    try {
        $params = [
            'CustomerIds' => array_keys($accountIds),
            'Extent' => ['Customers' => true]
        ];
        
        $startTime = microtime(true);
        $response = $mews->sendRequest('/customers/getAll', $params);
        $duration = round((microtime(true) - $startTime) * 1000);
        
        echo "  API call: POST /customers/getAll ({$duration}ms)\n";
        echo "  Requested " . count($accountIds) . " customer(s)\n";
        
        if (isset($response['Customers'])) {
            $customers = $response['Customers'];
            echo "  ✓ Retrieved " . count($customers) . " customers\n\n";
            
            // Build lookup map
            foreach ($customers as $customer) {
                $customersById[$customer['Id']] = $customer;
            }
            
            // Display customers table
            echo "  ┌──────────────────────────────────────┬────────────────────┬────────────────────┬──────────────────────────┐\n";
            echo "  │ Customer ID                          │ First Name         │ Last Name          │ Email                    │\n";
            echo "  ├──────────────────────────────────────┼────────────────────┼────────────────────┼──────────────────────────┤\n";
            
            $displayCount = min(count($customers), 15);
            for ($i = 0; $i < $displayCount; $i++) {
                $cust = $customers[$i];
                printf("  │ %-36s │ %-18s │ %-18s │ %-24s │\n",
                    $cust['Id'],
                    substr($cust['FirstName'] ?? 'N/A', 0, 18),
                    substr($cust['LastName'] ?? 'N/A', 0, 18),
                    substr($cust['Email'] ?? 'N/A', 0, 24)
                );
            }
            if (count($customers) > 15) {
                echo "  │ ... and " . (count($customers) - 15) . " more customers                                                                       │\n";
            }
            echo "  └──────────────────────────────────────┴────────────────────┴────────────────────┴──────────────────────────┘\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "  No customers to fetch (no AccountIds in reservations)\n\n";
}

// ============================================================================
// STEP 5: Match everything together
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 5: Matching reservations → resources → customers...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$matchedData = [];

foreach ($reservations as $res) {
    $resourceId = $res['AssignedResourceId'] ?? null;
    $accountId = $res['AccountId'] ?? null;
    
    $resource = $resourceId ? ($resourcesById[$resourceId] ?? null) : null;
    $customer = $accountId ? ($customersById[$accountId] ?? null) : null;
    
    $matchedData[] = [
        'reservation_id' => $res['Id'],
        'resource_id' => $resourceId,
        'room_name' => $resource['Name'] ?? 'Unknown',
        'room_state' => $resource['State'] ?? 'Unknown',
        'customer_id' => $accountId,
        'customer_name' => ($customer['FirstName'] ?? '') . ' ' . ($customer['LastName'] ?? ''),
        'check_in' => date('Y-m-d', strtotime($res['StartUtc'])),
        'check_out' => date('Y-m-d', strtotime($res['EndUtc'])),
        'state' => $res['State'] ?? 'Unknown'
    ];
}

echo "  COMBINED DATA (Reservation + Room + Guest):\n\n";
echo "  ┌────────────┬──────────────┬──────────────────────────┬────────────┬────────────┬──────────────┐\n";
echo "  │ Room       │ Room State   │ Guest Name               │ Check-in   │ Check-out  │ Res. State   │\n";
echo "  ├────────────┼──────────────┼──────────────────────────┼────────────┼────────────┼──────────────┤\n";

$displayCount = min(count($matchedData), 20);
for ($i = 0; $i < $displayCount; $i++) {
    $row = $matchedData[$i];
    printf("  │ %-10s │ %-12s │ %-24s │ %-10s │ %-10s │ %-12s │\n",
        substr($row['room_name'], 0, 10),
        substr($row['room_state'], 0, 12),
        substr(trim($row['customer_name']), 0, 24),
        $row['check_in'],
        $row['check_out'],
        substr($row['state'], 0, 12)
    );
}
if (count($matchedData) > 20) {
    echo "  │ ... and " . (count($matchedData) - 20) . " more                                                                              │\n";
}
echo "  └────────────┴──────────────┴──────────────────────────┴────────────┴────────────┴──────────────┘\n\n";

// ============================================================================
// STEP 6: Update random room states to 'Inspected'
// ============================================================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 6: Updating random room states to 'Inspected'...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Small delay to avoid rate limiting
usleep(500000); // 0.5 second

// Pick up to 3 random resources to update
$resourcesToUpdate = [];
$resourceKeys = array_keys($resourcesById);

if (count($resourceKeys) > 0) {
    shuffle($resourceKeys);
    $numToUpdate = min(3, count($resourceKeys));
    
    for ($i = 0; $i < $numToUpdate; $i++) {
        // Mews API requires State as an object with Value property
        $resourcesToUpdate[] = [
            'ResourceId' => $resourceKeys[$i],
            'State' => [
                'Value' => 'Inspected'
            ]
        ];
    }
    
    echo "  Selected " . count($resourcesToUpdate) . " resources to update:\n";
    foreach ($resourcesToUpdate as $update) {
        $name = $resourcesById[$update['ResourceId']]['Name'] ?? 'Unknown';
        $currentState = $resourcesById[$update['ResourceId']]['State'] ?? 'Unknown';
        echo "    - $name (ID: {$update['ResourceId']})\n";
        echo "      Current state: $currentState → New state: Inspected\n";
    }
    echo "\n";
    
    // Make the API call to update resources
    $params = [
        'ResourceUpdates' => $resourcesToUpdate
    ];
    
    echo "  Request payload:\n";
    echo "  " . json_encode($params, JSON_PRETTY_PRINT) . "\n\n";
    
    try {
        $startTime = microtime(true);
        $response = $mews->sendRequest('/resources/update', $params);
        $duration = round((microtime(true) - $startTime) * 1000);
        
        echo "  API call: POST /resources/update ({$duration}ms)\n";
        
        if (isset($response['Resources'])) {
            echo "  ✓ Successfully updated " . count($response['Resources']) . " resources\n\n";
            
            echo "  Updated resources:\n";
            echo "  ┌──────────────────────────────────────┬────────────┬──────────────┐\n";
            echo "  │ Resource ID                          │ Name       │ New State    │\n";
            echo "  ├──────────────────────────────────────┼────────────┼──────────────┤\n";
            
            foreach ($response['Resources'] as $resource) {
                printf("  │ %-36s │ %-10s │ %-12s │\n",
                    $resource['Id'],
                    substr($resource['Name'] ?? 'N/A', 0, 10),
                    $resource['State'] ?? 'N/A'
                );
            }
            echo "  └──────────────────────────────────────┴────────────┴──────────────┘\n";
        } else {
            echo "  Response:\n";
            print_r($response);
        }
    } catch (Exception $e) {
        echo "  ✗ Error updating resources: " . $e->getMessage() . "\n\n";
        echo "  Checking error log for details...\n";
        echo "  (Check PHP error log or logs/auth_debug.log for full API response)\n\n";
        echo "  Common causes of HTTP 400:\n";
        echo "    1. Incorrect parameter structure (should use StateData.State)\n";
        echo "    2. Invalid state value (valid: Dirty, Clean, Inspected, OutOfService, OutOfOrder)\n";
        echo "    3. Missing required permissions in API credentials\n";
        echo "    4. Resource is in a state that cannot be changed\n\n";
        echo "  Available resource states:\n";
        echo "    • Dirty        - Room needs cleaning\n";
        echo "    • Clean        - Room has been cleaned\n";
        echo "    • Inspected    - Room has been inspected and is ready\n";
        echo "    • OutOfService - Room temporarily unavailable\n";
        echo "    • OutOfOrder   - Room out of order (maintenance)\n";
    }
} else {
    echo "  No resources available to update\n";
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                           TEST COMPLETE                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

echo "SUMMARY:\n";
echo "  • Resources fetched: " . count($resources) . "\n";
echo "  • Reservations fetched: " . count($reservations) . "\n";
echo "  • Customers fetched: " . count($customers) . "\n";
echo "  • Matched records: " . count($matchedData) . "\n";
echo "  • Resources updated: " . count($resourcesToUpdate) . "\n\n";

echo "API ENDPOINTS USED:\n";
echo "  1. GET  /resources/getAll              - Fetch all rooms\n";
echo "  2. POST /reservations/getAll/2023-06-06 - Fetch reservations\n";
echo "  3. POST /customers/getAll              - Fetch customer details\n";
echo "  4. POST /resources/update              - Update room states\n";
