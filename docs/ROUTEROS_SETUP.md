# RouterOS Hotspot Configuration for MAC Address Detection

This document explains how to configure RouterOS to properly pass MAC addresses to the WiFi authentication system.

## RouterOS Hotspot Configuration

### Method 1: Basic Hotspot Setup (Recommended)

```bash
# 1. Configure hotspot server to pass client information
/ip hotspot profile
set default html-directory=hotspot login-by=http-pap use-radius=no

# 2. Configure hotspot to pass MAC address in headers
/ip hotspot profile
set default http-proxy=127.0.0.1:8080

# 3. Set up URL redirection to pass MAC
/ip hotspot walled-garden
add dst-host=your-server.com
```

### Method 2: Using RADIUS Attributes

```bash
# Configure hotspot to use RADIUS and pass MAC as attribute
/ip hotspot profile
set default use-radius=yes

/radius
add address=YOUR_SERVER_IP secret=YOUR_SECRET service=hotspot
```

### Method 3: Custom Header Configuration

```bash
# Configure RouterOS to pass MAC in custom header
# This requires RouterOS scripting or proxy configuration
/ip proxy
set enabled=yes port=8080

# Add custom header script (RouterOS v7+)
/ip proxy access
add action=allow dst-port=80,443 method=get,post
```

## Server Variables Available in RouterOS

When properly configured, RouterOS hotspot provides these server variables:

- `$_SERVER['REMOTE_ADDR']` - Client IP address
- `$_SERVER['HTTP_X_FORWARDED_FOR']` - May contain MAC or IP
- `$_SERVER['HTTP_X_REAL_IP']` - Real client IP
- `$_SERVER['REMOTE_USER']` - May contain MAC in some configs
- `$_GET['mac']` - MAC passed as URL parameter

## Testing MAC Address Detection

### 1. Check Server Variables

Create a test PHP file to see what RouterOS provides:

```php
<?php
echo "<h3>Server Variables:</h3>";
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 || in_array($key, ['REMOTE_ADDR', 'REMOTE_USER'])) {
        echo "$key: $value<br>";
    }
}

echo "<h3>GET Parameters:</h3>";
var_dump($_GET);

echo "<h3>POST Parameters:</h3>";
var_dump($_POST);
?>
```

### 2. RouterOS Template Variables

In your RouterOS hotspot templates, you can use these variables:

- `$(mac)` - Client MAC address
- `$(ip)` - Client IP address
- `$(username)` - Username (if authenticated)
- `$(link-orig)` - Original requested URL

### 3. Modify Login Form

Update your login.html to include MAC as hidden field:

```html
<!-- Add this to your form -->
<input type="hidden" name="client_mac" value="$(mac)">
<input type="hidden" name="client_ip" value="$(ip)">
```

## Authentication Script Updates

### Option 1: Use RouterOS Template Variables

Modify `authenticate.php` to get MAC from form data:

```php
function getClientMAC() {
    // First check if RouterOS passed MAC via form
    if (isset($_POST['client_mac']) && preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $_POST['client_mac'])) {
        return strtoupper(str_replace('-', ':', $_POST['client_mac']));
    }
    
    // Fallback to server variables...
    // (rest of the function)
}
```

### Option 2: RouterOS API Integration

For advanced setups, integrate with RouterOS API:

```php
require_once 'routeros_api.php';

function getMACFromRouterOS($ip) {
    $api = new RouterosAPI();
    $api->connect('192.168.1.1', 'admin', 'password');
    
    $leases = $api->comm('/ip/dhcp-server/lease/print', array(
        '?address' => $ip
    ));
    
    foreach ($leases as $lease) {
        if (isset($lease['mac-address'])) {
            return $lease['mac-address'];
        }
    }
    
    return null;
}
```

## Troubleshooting

### Common Issues:

1. **MAC not detected**: Check RouterOS hotspot profile configuration
2. **Invalid MAC format**: Ensure RouterOS passes MAC in correct format
3. **Server variables empty**: Verify hotspot template configuration

### Debug Steps:

1. Check RouterOS hotspot logs: `/log print where topics~"hotspot"`
2. Test with simple PHP script to see available variables
3. Verify hotspot profile settings
4. Check if firewall rules block necessary traffic

### Development Mode

For testing without RouterOS, the system generates consistent MAC addresses based on IP and User-Agent, allowing development and testing of the authentication flow.

## Security Considerations

1. **Validate MAC format** to prevent injection attacks
2. **Log MAC access** for audit trails  
3. **Rate limit** authentication attempts per MAC
4. **Encrypt communication** between RouterOS and auth server

## Example RouterOS Export

```
/ip hotspot profile
set [ find default=yes ] hotspot-address=192.168.1.1 html-directory=hotspot \
    http-cookie-lifetime=3d http-proxy=127.0.0.1:8080 login-by=\
    http-pap name=default rate-limit="" smtp-server=0.0.0.0 \
    split-user-domain=no use-radius=no

/ip hotspot server
add address-pool=dhcp_pool1 disabled=no interface=bridge name=hotspot1 \
    profile=default

/ip hotspot walled-garden
add disabled=no dst-host=your-auth-server.com
```

This configuration ensures that MAC addresses are properly passed from RouterOS to your authentication system.