<?php
/**
 * Mews Rooms Sync Script
 * Fetches all resources (rooms) from Mews and syncs them to the database
 * 
 * Access via: http://localhost/MOA-Wifi/public/sync_rooms.php
 * 
 * DELETE THIS FILE AFTER USE
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../src/MewsConnector.php';

// Load config
$db_config = require __DIR__ . '/../config/config.php';
$mews_config = require __DIR__ . '/../config/mews_config.php';
$environment = $mews_config['mews']['environment'] ?? 'demo';

echo "<h1>Mews Rooms Sync</h1>";

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['database']};charset=utf8",
        $db_config['username'],
        $db_config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<p>✓ Database connected</p>";
} catch (PDOException $e) {
    echo "<p style='color:red'>✗ Database error: " . $e->getMessage() . "</p>";
    exit;
}

// Connect to Mews
try {
    $mews = new MewsConnector($environment);
    echo "<p>✓ Connected to Mews ($environment)</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Mews error: " . $e->getMessage() . "</p>";
    exit;
}

// Fetch resources from Mews
echo "<h2>Fetching Resources from Mews...</h2>";

try {
    $response = $mews->getResources();
    
    if (!isset($response['Resources']) || empty($response['Resources'])) {
        echo "<p>No resources found.</p>";
        exit;
    }
    
    $resources = $response['Resources'];
    echo "<p>Found <strong>" . count($resources) . "</strong> resources</p>";
    
    // Filter to only include actual rooms
    $rooms = [];
    foreach ($resources as $resource) {
        if (isset($resource['Id']) && (isset($resource['Number']) || isset($resource['Name']))) {
            $rooms[] = [
                'id' => $resource['Id'],
                'name' => $resource['Number'] ?? $resource['Name'],
                'state' => $resource['State'] ?? null
            ];
        }
    }
    
    // Sort by room name/number
    usort($rooms, function($a, $b) {
        return strnatcmp($a['name'], $b['name']);
    });
    
    echo "<h2>Resources Found: " . count($rooms) . "</h2>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>#</th><th>Room Number</th><th>Mews ID</th><th>State</th></tr>";
    $i = 1;
    foreach ($rooms as $room) {
        echo "<tr>";
        echo "<td>$i</td>";
        echo "<td><strong>{$room['name']}</strong></td>";
        echo "<td style='font-family: monospace; font-size: 11px;'>{$room['id']}</td>";
        echo "<td>{$room['state']}</td>";
        echo "</tr>";
        $i++;
    }
    echo "</table>";
    
    // Auto-sync button
    echo "<h2>Sync to Database</h2>";
    echo "<form method='post'>";
    echo "<button type='submit' name='sync' style='padding: 10px 20px; font-size: 16px; cursor: pointer; background: #002554; color: white; border: none; border-radius: 5px;'>🔄 Sync " . count($rooms) . " Rooms to Database</button>";
    echo "</form>";
    
    // Handle sync
    if (isset($_POST['sync'])) {
        echo "<h3>Sync Results:</h3>";
        echo "<pre>";
        
        $inserted = 0;
        $updated = 0;
        $errors = 0;
        
        foreach ($rooms as $room) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO rooms (id, name) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name)
                ");
                $stmt->execute([$room['id'], $room['name']]);
                
                if ($stmt->rowCount() == 1) {
                    echo "✓ Inserted: {$room['name']}\n";
                    $inserted++;
                } elseif ($stmt->rowCount() == 2) {
                    echo "↻ Updated: {$room['name']}\n";
                    $updated++;
                } else {
                    echo "- Unchanged: {$room['name']}\n";
                }
            } catch (PDOException $e) {
                echo "✗ Error for {$room['name']}: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
        
        echo "\n========================================\n";
        echo "Summary:\n";
        echo "  Inserted: $inserted\n";
        echo "  Updated:  $updated\n";
        echo "  Errors:   $errors\n";
        echo "  Total:    " . count($rooms) . "\n";
        echo "========================================\n";
        echo "</pre>";
        
        if ($errors == 0) {
            echo "<p style='color: green; font-size: 18px;'>✅ Sync completed successfully!</p>";
        }
    }
    
    // SQL export option
    echo "<h2>SQL Export (Manual Option)</h2>";
    echo "<textarea style='width:100%; height:150px; font-family:monospace;'>";
    echo "-- Run this SQL to populate rooms table\n";
    echo "TRUNCATE TABLE rooms;\n\n";
    echo "INSERT INTO rooms (id, name) VALUES\n";
    $values = [];
    foreach ($rooms as $room) {
        $id = addslashes($room['id']);
        $name = addslashes($room['name']);
        $values[] = "('$id', '$name')";
    }
    echo implode(",\n", $values) . ";\n";
    echo "</textarea>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error fetching resources: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>⚠️ DELETE THIS FILE AFTER USE!</strong></p>";
?>
