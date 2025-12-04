<?php
/**
 * MikroTik RouterOS API Test Script
 * Tests connection and basic operations with your MikroTik router
 * 
 * Access via: http://localhost/MOA-Wifi/public/test_mikrotik.php
 * 
 * DELETE THIS FILE AFTER TESTING
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>MikroTik RouterOS API Test</h1>";
echo "<pre>";

// Server info
echo "=== SERVER ENVIRONMENT ===\n";
echo "PHP Version:    " . phpversion() . "\n";
echo "Server IP:      " . ($_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname())) . "\n";
echo "Server Name:    " . ($_SERVER['SERVER_NAME'] ?? 'Unknown') . "\n";
echo "Script Path:    " . __FILE__ . "\n";
echo "Current Time:   " . date('Y-m-d H:i:s') . "\n";
echo "Timezone:       " . date_default_timezone_get() . "\n";
echo "\n";

// Load config
$config = require __DIR__ . '/../config/config.php';

echo "=== CONFIGURATION ===\n";
echo "Config File:     " . realpath(__DIR__ . '/../config/config.php') . "\n";
echo "MikroTik Enabled: " . ($config['mikrotik']['enabled'] ? 'Yes' : 'No') . "\n";
echo "Host: {$config['mikrotik']['host']}\n";
echo "Port: {$config['mikrotik']['port']}\n";
echo "User: {$config['mikrotik']['user']}\n";
echo "Pass: " . str_repeat('*', strlen($config['mikrotik']['pass'])) . " (" . strlen($config['mikrotik']['pass']) . " chars)\n";
echo "Profiles:\n";
echo "  Normal: {$config['mikrotik']['profiles']['normal']}\n";
echo "  Fast:   {$config['mikrotik']['profiles']['fast']}\n";
echo "\n";

// Network diagnostics
echo "=== NETWORK DIAGNOSTICS ===\n";
$host = $config['mikrotik']['host'];
$port = $config['mikrotik']['port'];

// DNS resolution
echo "1. DNS Resolution for '$host':\n";
$ip = gethostbyname($host);
if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
    echo "   ✗ DNS resolution FAILED - cannot resolve hostname\n";
} else {
    echo "   ✓ Resolved to: $ip\n";
}

// Check if it's a private IP being accessed from external server
echo "\n2. IP Address Analysis:\n";
$isPrivate = false;
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $ip)) {
        $isPrivate = true;
        echo "   ⚠ WARNING: $ip is a PRIVATE IP address!\n";
        echo "   ⚠ If this script runs on an external server, it CANNOT reach private IPs.\n";
        echo "   ⚠ You need to use the MikroTik's PUBLIC IP or set up a VPN.\n";
    } else {
        echo "   ✓ $ip appears to be a public IP address\n";
    }
} else {
    echo "   ? Unable to determine IP type\n";
}

// Port connectivity test
echo "\n3. TCP Port Connectivity Test ($ip:$port):\n";
echo "   Testing connection (5 second timeout)...\n";
$startTime = microtime(true);
$socket = @fsockopen($ip, $port, $errno, $errstr, 5);
$elapsed = round((microtime(true) - $startTime) * 1000);

if ($socket) {
    echo "   ✓ Port $port is OPEN and reachable (connected in {$elapsed}ms)\n";
    fclose($socket);
} else {
    echo "   ✗ Port $port is NOT reachable (failed after {$elapsed}ms)\n";
    echo "   Error Code: $errno\n";
    echo "   Error Message: $errstr\n";
    echo "\n   Possible causes:\n";
    if ($isPrivate) {
        echo "   → Private IP not reachable from this server (most likely)\n";
    }
    echo "   → MikroTik API service not enabled\n";
    echo "   → Firewall blocking port $port\n";
    echo "   → Wrong IP address\n";
    echo "   → Network routing issue\n";
}

// Traceroute-like test
echo "\n4. Quick Route Test:\n";
if (function_exists('shell_exec') && !stristr(ini_get('disable_functions'), 'shell_exec')) {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows - use tracert with 2 hops max
        echo "   (Limited traceroute on Windows)\n";
        $output = @shell_exec("ping -n 1 -w 1000 $ip 2>&1");
        if ($output) {
            if (stripos($output, 'TTL=') !== false) {
                echo "   ✓ Host is reachable via ping\n";
            } else {
                echo "   ✗ Host not reachable via ping\n";
            }
        }
    } else {
        // Linux - quick traceroute
        $output = @shell_exec("traceroute -m 5 -w 1 $ip 2>&1 | head -10");
        if ($output) {
            echo "   " . str_replace("\n", "\n   ", trim($output)) . "\n";
        }
    }
} else {
    echo "   (shell_exec disabled, cannot run network diagnostics)\n";
}

echo "\n";

// Check if library is installed
echo "=== LIBRARY CHECK ===\n";
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
echo "Looking for: " . realpath(__DIR__ . '/../') . "/vendor/autoload.php\n";

if (!file_exists($autoloadPath)) {
    echo "✗ vendor/autoload.php NOT FOUND\n";
    echo "\nRun this command to install:\n";
    echo "  cd " . realpath(__DIR__ . '/../') . "\n";
    echo "  composer require evilfreelancer/routeros-api-php\n";
    echo "</pre>";
    exit;
}

require_once $autoloadPath;
echo "✓ Autoloader found and loaded\n";

if (!class_exists('RouterOS\Client')) {
    echo "✗ RouterOS\\Client class NOT FOUND\n";
    echo "\nRun: composer require evilfreelancer/routeros-api-php\n";
    echo "</pre>";
    exit;
}

// Show library version if available
$composerLock = __DIR__ . '/../composer.lock';
if (file_exists($composerLock)) {
    $lock = json_decode(file_get_contents($composerLock), true);
    foreach ($lock['packages'] ?? [] as $package) {
        if ($package['name'] === 'evilfreelancer/routeros-api-php') {
            echo "✓ RouterOS library v" . $package['version'] . " installed\n";
            break;
        }
    }
} else {
    echo "✓ RouterOS library installed (version unknown)\n";
}
echo "\n";

// Test connection
echo "=== API CONNECTION TEST ===\n";
echo "Step 1: Creating client configuration...\n";
$clientConfig = [
    'host' => $config['mikrotik']['host'],
    'user' => $config['mikrotik']['user'],
    'pass' => $config['mikrotik']['pass'],
    'port' => $config['mikrotik']['port'],
    'timeout' => 10
];
echo "   Host:    {$clientConfig['host']}\n";
echo "   Port:    {$clientConfig['port']}\n";
echo "   User:    {$clientConfig['user']}\n";
echo "   Timeout: {$clientConfig['timeout']}s\n";
echo "\n";

echo "Step 2: Attempting connection...\n";
$connStart = microtime(true);

try {
    $client = new \RouterOS\Client($clientConfig);
    $connTime = round((microtime(true) - $connStart) * 1000);
    echo "   ✓ Connected successfully in {$connTime}ms!\n\n";
} catch (\RouterOS\Exceptions\ConnectException $e) {
    $connTime = round((microtime(true) - $connStart) * 1000);
    echo "   ✗ Connection FAILED after {$connTime}ms\n\n";
    echo "Exception Type: " . get_class($e) . "\n";
    echo "Error Message:  " . $e->getMessage() . "\n";
    echo "Error File:     " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n";
    echo "=== DIAGNOSIS ===\n";
    if (strpos($e->getMessage(), 'timed out') !== false) {
        echo "The connection TIMED OUT. This usually means:\n";
        if ($isPrivate) {
            echo "  1. ★ MOST LIKELY: This server cannot reach the private IP $ip\n";
            echo "     → Solution: Use the MikroTik's PUBLIC IP address\n";
            echo "     → Or: Run this script from a server on the same network\n\n";
        }
        echo "  2. Firewall is blocking port $port on the MikroTik\n";
        echo "     → Run on MikroTik: /ip firewall filter add chain=input protocol=tcp dst-port=$port action=accept\n\n";
        echo "  3. The IP address $ip is incorrect\n";
        echo "     → Verify the correct IP of your MikroTik router\n\n";
        echo "  4. Network routing issue between this server and MikroTik\n";
    } elseif (strpos($e->getMessage(), 'refused') !== false) {
        echo "Connection was REFUSED. This means:\n";
        echo "  1. MikroTik API service is disabled\n";
        echo "     → Run on MikroTik: /ip service enable api\n";
        echo "  2. API is bound to different port\n";
        echo "     → Check: /ip service print where name=api\n";
    } elseif (strpos($e->getMessage(), 'authentication') !== false || strpos($e->getMessage(), 'login') !== false) {
        echo "AUTHENTICATION failed. This means:\n";
        echo "  1. Wrong username or password\n";
        echo "  2. User doesn't have API access\n";
        echo "     → Check user permissions in MikroTik\n";
    }
    echo "\n";
    echo "=== MIKROTIK COMMANDS TO RUN ===\n";
    echo "Check API service:    /ip service print where name=api\n";
    echo "Enable API:           /ip service enable api\n";
    echo "Check firewall:       /ip firewall filter print where dst-port=$port\n";
    echo "Add firewall rule:    /ip firewall filter add chain=input protocol=tcp dst-port=$port action=accept comment=\"Allow API\"\n";
    echo "</pre>";
    exit;
} catch (Exception $e) {
    $connTime = round((microtime(true) - $connStart) * 1000);
    echo "   ✗ Connection FAILED after {$connTime}ms\n\n";
    echo "Exception Type: " . get_class($e) . "\n";
    echo "Error Message:  " . $e->getMessage() . "\n";
    echo "</pre>";
    exit;
}

// Get router identity
echo "=== ROUTER INFO ===\n";
try {
    echo "Querying /system/identity/print...\n";
    $query = new \RouterOS\Query('/system/identity/print');
    $response = $client->query($query)->read();
    echo "   Router Name: " . ($response[0]['name'] ?? 'Unknown') . "\n";
    
    echo "Querying /system/resource/print...\n";
    $query = new \RouterOS\Query('/system/resource/print');
    $response = $client->query($query)->read();
    echo "   RouterOS Version: " . ($response[0]['version'] ?? 'Unknown') . "\n";
    echo "   Board: " . ($response[0]['board-name'] ?? 'Unknown') . "\n";
    echo "   Architecture: " . ($response[0]['architecture-name'] ?? 'Unknown') . "\n";
    echo "   CPU: " . ($response[0]['cpu'] ?? 'Unknown') . "\n";
    echo "   CPU Load: " . ($response[0]['cpu-load'] ?? 'Unknown') . "%\n";
    echo "   Free Memory: " . (isset($response[0]['free-memory']) ? round($response[0]['free-memory'] / 1048576, 1) . ' MB' : 'Unknown') . "\n";
    echo "   Uptime: " . ($response[0]['uptime'] ?? 'Unknown') . "\n";
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error getting router info: " . $e->getMessage() . "\n\n";
}

// Check hotspot status
echo "=== HOTSPOT SERVICE STATUS ===\n";
try {
    echo "Querying /ip/hotspot/print...\n";
    $query = new \RouterOS\Query('/ip/hotspot/print');
    $hotspots = $client->query($query)->read();
    
    if (empty($hotspots)) {
        echo "   ⚠ No hotspot servers found!\n";
        echo "   The hotspot service may not be configured.\n";
    } else {
        echo "   Found " . count($hotspots) . " hotspot server(s):\n";
        foreach ($hotspots as $hs) {
            $disabled = isset($hs['disabled']) && $hs['disabled'] === 'true';
            $status = $disabled ? '✗ DISABLED' : '✓ Enabled';
            echo "   - {$hs['name']} on {$hs['interface']} ($status)\n";
            echo "     Profile: " . ($hs['profile'] ?? 'default') . "\n";
            echo "     Address Pool: " . ($hs['address-pool'] ?? 'none') . "\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error getting hotspot status: " . $e->getMessage() . "\n\n";
}

// Check active hotspot users
echo "=== ACTIVE HOTSPOT SESSIONS ===\n";
try {
    echo "Querying /ip/hotspot/active/print...\n";
    $query = new \RouterOS\Query('/ip/hotspot/active/print');
    $active = $client->query($query)->read();
    
    echo "   Active sessions: " . count($active) . "\n";
    if (!empty($active)) {
        $shown = 0;
        foreach ($active as $session) {
            if ($shown >= 5) {
                echo "   ... and " . (count($active) - 5) . " more active sessions\n";
                break;
            }
            echo "   - User: " . ($session['user'] ?? 'Unknown');
            echo " | MAC: " . ($session['mac-address'] ?? 'Unknown');
            echo " | IP: " . ($session['address'] ?? 'Unknown');
            echo " | Uptime: " . ($session['uptime'] ?? 'Unknown') . "\n";
            $shown++;
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error getting active sessions: " . $e->getMessage() . "\n\n";
}

// Check hotspot profiles
echo "=== HOTSPOT USER PROFILES ===\n";
try {
    echo "Querying /ip/hotspot/user/profile/print...\n";
    $query = new \RouterOS\Query('/ip/hotspot/user/profile/print');
    $profiles = $client->query($query)->read();
    
    if (empty($profiles)) {
        echo "✗ No hotspot user profiles found!\n";
        echo "  Create profiles with:\n";
        echo "  /ip hotspot user profile add name={$config['mikrotik']['profiles']['normal']} rate-limit=10M/10M\n";
        echo "  /ip hotspot user profile add name={$config['mikrotik']['profiles']['fast']} rate-limit=20M/20M\n";
    } else {
        echo "   Found " . count($profiles) . " profile(s):\n";
        $normalFound = false;
        $fastFound = false;
        
        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? 'Unknown';
            $rateLimit = $profile['rate-limit'] ?? 'No limit set';
            $sessionTimeout = $profile['session-timeout'] ?? 'None';
            $idleTimeout = $profile['idle-timeout'] ?? 'None';
            $sharedUsers = $profile['shared-users'] ?? '1';
            
            $marker = '';
            if ($name === $config['mikrotik']['profiles']['normal']) {
                $marker = ' ★ NORMAL PROFILE';
                $normalFound = true;
            } elseif ($name === $config['mikrotik']['profiles']['fast']) {
                $marker = ' ★ FAST PROFILE';
                $fastFound = true;
            }
            
            echo "   - $name$marker\n";
            echo "     Rate Limit: $rateLimit\n";
            echo "     Session Timeout: $sessionTimeout | Idle: $idleTimeout\n";
            echo "     Shared Users: $sharedUsers\n";
        }
        
        echo "\n";
        if (!$normalFound) {
            echo "   ⚠ Profile '{$config['mikrotik']['profiles']['normal']}' NOT FOUND - required for normal speed\n";
        }
        if (!$fastFound) {
            echo "   ⚠ Profile '{$config['mikrotik']['profiles']['fast']}' NOT FOUND - required for fast speed\n";
        }
        if ($normalFound && $fastFound) {
            echo "   ✓ Both required profiles exist\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error getting profiles: " . $e->getMessage() . "\n\n";
}

// Check existing hotspot users
echo "=== HOTSPOT USERS (configured) ===\n";
try {
    echo "Querying /ip/hotspot/user/print...\n";
    $query = new \RouterOS\Query('/ip/hotspot/user/print');
    $users = $client->query($query)->read();
    
    echo "   Total configured users: " . count($users) . "\n";
    if (!empty($users)) {
        echo "   Recent users:\n";
        $shown = 0;
        // Show last 10 users (reversed)
        $recentUsers = array_slice(array_reverse($users), 0, 10);
        foreach ($recentUsers as $user) {
            $name = $user['name'] ?? 'Unknown';
            $profile = $user['profile'] ?? 'default';
            $comment = $user['comment'] ?? '';
            $disabled = isset($user['disabled']) && $user['disabled'] === 'true' ? ' [DISABLED]' : '';
            echo "   - $name (profile: $profile)$disabled" . ($comment ? "\n     Comment: $comment" : "") . "\n";
            $shown++;
        }
        if (count($users) > 10) {
            echo "   ... showing last 10 of " . count($users) . " total users\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error getting users: " . $e->getMessage() . "\n\n";
}

// Test creating/updating a user
echo "=== USER CREATION TEST ===\n";
$testRoom = 'test_room_' . rand(1000, 9999);
$testPass = 'TestGuest';
$testProfile = $config['mikrotik']['profiles']['normal'];

echo "Testing user creation (simulates what authenticate.php does):\n";
echo "   Username: $testRoom\n";
echo "   Password: $testPass\n";
echo "   Profile:  $testProfile\n\n";

try {
    // Step 1: Check if user exists
    echo "Step 1: Checking if user already exists...\n";
    echo "   Query: /ip/hotspot/user/print where name=$testRoom\n";
    $query = new \RouterOS\Query('/ip/hotspot/user/print');
    $query->where('name', $testRoom);
    $existing = $client->query($query)->read();
    echo "   Result: " . (empty($existing) ? "User does not exist" : "User exists with ID " . $existing[0]['.id']) . "\n\n";
    
    if (empty($existing)) {
        // Step 2: Create new user
        echo "Step 2: Creating new user...\n";
        echo "   Query: /ip/hotspot/user/add\n";
        echo "   Parameters:\n";
        echo "     name=$testRoom\n";
        echo "     password=$testPass\n";
        echo "     profile=$testProfile\n";
        echo "     comment=Test user - delete me\n";
        
        $addQuery = new \RouterOS\Query('/ip/hotspot/user/add');
        $addQuery->equal('name', $testRoom);
        $addQuery->equal('password', $testPass);
        $addQuery->equal('profile', $testProfile);
        $addQuery->equal('comment', 'Test user - safe to delete - ' . date('Y-m-d H:i:s'));
        $result = $client->query($addQuery)->read();
        echo "   Result: " . json_encode($result) . "\n";
        echo "   ✓ User created successfully!\n\n";
    } else {
        // Step 2 (alt): Update existing user
        echo "Step 2: Updating existing user...\n";
        echo "   Query: /ip/hotspot/user/set\n";
        echo "   Parameters:\n";
        echo "     .id=" . $existing[0]['.id'] . "\n";
        echo "     password=$testPass\n";
        echo "     profile=$testProfile\n";
        
        $setQuery = new \RouterOS\Query('/ip/hotspot/user/set');
        $setQuery->equal('.id', $existing[0]['.id']);
        $setQuery->equal('password', $testPass);
        $setQuery->equal('profile', $testProfile);
        $result = $client->query($setQuery)->read();
        echo "   Result: " . json_encode($result) . "\n";
        echo "   ✓ User updated successfully!\n\n";
    }
    
    // Step 3: Verify user exists
    echo "Step 3: Verifying user was created/updated...\n";
    $query = new \RouterOS\Query('/ip/hotspot/user/print');
    $query->where('name', $testRoom);
    $verify = $client->query($query)->read();
    
    if (!empty($verify)) {
        echo "   ✓ User verified in MikroTik:\n";
        echo "     ID: " . $verify[0]['.id'] . "\n";
        echo "     Name: " . $verify[0]['name'] . "\n";
        echo "     Profile: " . ($verify[0]['profile'] ?? 'default') . "\n";
        echo "     Comment: " . ($verify[0]['comment'] ?? 'none') . "\n\n";
        
        // Step 4: Clean up - delete test user
        echo "Step 4: Cleaning up test user...\n";
        echo "   Query: /ip/hotspot/user/remove .id=" . $verify[0]['.id'] . "\n";
        $delQuery = new \RouterOS\Query('/ip/hotspot/user/remove');
        $delQuery->equal('.id', $verify[0]['.id']);
        $client->query($delQuery)->read();
        echo "   ✓ Test user deleted\n\n";
        
        // Verify deletion
        $query = new \RouterOS\Query('/ip/hotspot/user/print');
        $query->where('name', $testRoom);
        $checkDeleted = $client->query($query)->read();
        if (empty($checkDeleted)) {
            echo "   ✓ Deletion verified - user no longer exists\n";
        } else {
            echo "   ⚠ User still exists after deletion attempt\n";
        }
    } else {
        echo "   ✗ Could not verify user creation!\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error during user test: " . $e->getMessage() . "\n";
    echo "Exception: " . get_class($e) . "\n";
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════\n";
echo "║ ✅ ALL TESTS COMPLETED SUCCESSFULLY\n";
echo "║ \n";
echo "║ MikroTik integration is ready for use!\n";
echo "║ \n";
echo "║ Connection: {$config['mikrotik']['host']}:{$config['mikrotik']['port']}\n";
echo "║ Profiles:   {$config['mikrotik']['profiles']['normal']} / {$config['mikrotik']['profiles']['fast']}\n";
echo "╚════════════════════════════════════════════════════════════════════\n";

echo "</pre>";
echo "<hr>";
echo "<p><strong>⚠️ DELETE THIS FILE AFTER TESTING!</strong></p>";
?>
