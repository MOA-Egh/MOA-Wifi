<!DOCTYPE html>
<html>
<head>
    <title>MOA WiFi - Debug Info</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
        .warning { background: #fff3cd; border-color: #ffeaa7; padding: 10px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>
    <h1>MOA WiFi System - Debug Information</h1>
    
    <div class="warning">
        <strong>⚠️ Security Warning:</strong> Remove this file in production! This page exposes system information.
    </div>

    <div class="section">
        <h2>🌐 Server Variables (HTTP Headers)</h2>
        <table>
            <tr><th>Variable</th><th>Value</th></tr>
            <?php
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0 || in_array($key, ['REMOTE_ADDR', 'REMOTE_USER', 'SERVER_NAME', 'REQUEST_URI'])) {
                    echo "<tr><td>$key</td><td>" . htmlspecialchars($value) . "</td></tr>";
                }
            }
            ?>
        </table>
    </div>

    <div class="section">
        <h2>📨 GET Parameters</h2>
        <?php if (empty($_GET)): ?>
            <p><em>No GET parameters received</em></p>
        <?php else: ?>
            <table>
                <tr><th>Parameter</th><th>Value</th></tr>
                <?php foreach ($_GET as $key => $value): ?>
                    <tr><td><?= htmlspecialchars($key) ?></td><td><?= htmlspecialchars($value) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>📬 POST Parameters</h2>
        <?php if (empty($_POST)): ?>
            <p><em>No POST parameters received</em></p>
        <?php else: ?>
            <table>
                <tr><th>Parameter</th><th>Value</th></tr>
                <?php foreach ($_POST as $key => $value): ?>
                    <tr><td><?= htmlspecialchars($key) ?></td><td><?= htmlspecialchars($value) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>🔍 MAC Address Detection Test</h2>
        <?php
        // Include our authentication functions
        require_once 'authenticate.php';
        
        try {
            $detected_mac = getClientMAC();
            echo "<p><strong>✅ Detected MAC:</strong> <code>$detected_mac</code></p>";
            
            if (isDevelopmentMode()) {
                echo "<p><em>🧪 Running in development mode - MAC generated for testing</em></p>";
            }
        } catch (Exception $e) {
            echo "<p><strong>❌ MAC Detection Failed:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <div class="section">
        <h2>🏨 Mews Connection Test</h2>
        <?php
        try {
            require_once 'mews_wifi_auth.php';
            $mews_config = require 'mews_config.php';
            $mews_auth = new MewsWifiAuth($mews_config['mews']['environment']);
            $env_info = $mews_auth->getEnvironmentInfo();
            
            echo "<p><strong>✅ Mews Status:</strong> " . $env_info['status'] . "</p>";
            echo "<p><strong>Environment:</strong> " . $env_info['environment'] . "</p>";
            
        } catch (Exception $e) {
            echo "<p><strong>❌ Mews Connection Failed:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <div class="section">
        <h2>💾 Database Connection Test</h2>
        <?php
        try {
            $db_config = require 'config.php';
            $pdo = new PDO(
                "mysql:host={$db_config['host']};dbname={$db_config['database']};charset=utf8",
                $db_config['username'],
                $db_config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            echo "<p><strong>✅ Database:</strong> Connected successfully</p>";
            
            // Test table existence
            $tables = ['authorized_devices', 'rooms_to_skip'];
            foreach ($tables as $table) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                    $count = $stmt->fetchColumn();
                    echo "<p><strong>Table '$table':</strong> $count records</p>";
                } catch (Exception $e) {
                    echo "<p><strong>❌ Table '$table':</strong> " . $e->getMessage() . "</p>";
                }
            }
            
        } catch (Exception $e) {
            echo "<p><strong>❌ Database Connection Failed:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <div class="section">
        <h2>📋 RouterOS Configuration Help</h2>
        <p>For proper MAC address detection, ensure your RouterOS hotspot is configured with:</p>
        <ol>
            <li>Hotspot profile set to use templates with <code>$(mac)</code> variable</li>
            <li>Login page includes hidden input: <code>&lt;input type="hidden" name="client_mac" value="$(mac)"&gt;</code></li>
            <li>Walled garden allows access to your authentication server</li>
        </ol>
        <p><a href="ROUTEROS_SETUP.md">📖 View full RouterOS setup guide</a></p>
    </div>

    <div class="section">
        <h2>🔗 Quick Actions</h2>
        <ul>
            <li><a href="login.html">🔐 Login Page</a></li>
            <li><a href="admin.html">⚙️ Admin Interface</a></li>
            <li><a href="admin_api.php?action=get_statistics">📊 System Statistics (JSON)</a></li>
        </ul>
    </div>

    <hr>
    <p><small>Generated at: <?= date('Y-m-d H:i:s') ?> | IP: <?= $_SERVER['REMOTE_ADDR'] ?? 'unknown' ?></small></p>
</body>
</html>