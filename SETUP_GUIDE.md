# MOA Hotel WiFi Management System - Setup Guide

## Prerequisites

1. **XAMPP/WAMP/MAMP** or similar PHP development environment
2. **MySQL Database** (included with XAMPP)
3. **RouterOS Hotspot** configured on your MikroTik router
4. **PHP 7.4+** with PDO MySQL extension

## Installation Steps

### 1. Database Setup

1. Start your MySQL server (via XAMPP Control Panel)
2. Open phpMyAdmin (http://localhost/phpmyadmin)
3. Create a new database named `moa_wifi_management`
4. Import the database structure:
   ```sql
   -- Run the contents of database_setup.sql
   ```

### 2. File Installation

1. Copy all files to your web server directory:
   ```
   c:\xampp\htdocs\MOA-Wifi\
   ```

2. Update database configuration in `config.php`:
   ```php
   return [
       'host' => 'localhost',
       'database' => 'moa_wifi_management', 
       'username' => 'root',  // Your DB username
       'password' => '',      // Your DB password
       'charset' => 'utf8mb4'
   ];
   ```

### 3. RouterOS Hotspot Configuration

Configure your MikroTik RouterOS hotspot to use these files:

1. **Upload Files to Router:**
   ```
   /file upload
   # Upload all HTML files to the router's hotspot directory
   ```

2. **Configure Hotspot Server:**
   ```
   /ip hotspot profile
   set default html-directory=hotspot login-by=http-pap
   ```

3. **Set Authentication URL:**
   ```
   /ip hotspot profile
   set default html-directory-override=""
   set default login-by=http-pap,cookie
   ```

### 4. RouterOS Integration

For proper MAC address detection and user authentication, you'll need to modify the RouterOS hotspot configuration:

1. **Enable HTTP PAP Authentication:**
   ```
   /ip hotspot profile set default login-by=http-pap
   ```

2. **Configure RADIUS (Optional):**
   If using RADIUS for authentication:
   ```
   /radius
   add address=YOUR_SERVER_IP secret="your_secret" service=hotspot
   ```

### 5. Mews PMS Configuration

1. **Set up Mews Authentication:**
   Create INI files with your Mews credentials in `/ini/` directory:
   
   **For Demo Environment** (`/ini/demo_mews.ini`):
   ```ini
   ClientToken = "your_demo_client_token"
   AccessToken = "your_demo_access_token"
   Client = "your_demo_client_id"
   EnterpriseId = "your_demo_enterprise_id"
   ```

   **For Production Environment** (`/ini/prod_mews.ini`):
   ```ini
   ClientToken = "your_prod_client_token"
   AccessToken = "your_prod_access_token"
   Client = "your_prod_client_id" 
   EnterpriseId = "your_prod_enterprise_id"
   ```

2. **Configure Mews Environment:**
   Edit `mews_config.php` to set your environment:
   ```php
   'environment' => 'demo', // Change to 'prod' for production
   ```

3. **Test Mews Integration:**
   The system includes fallback test data for development when Mews API is unavailable

### 6. Testing

1. **Test Database Connection:**
   Visit: `http://localhost/MOA-Wifi/admin_api.php?action=get_statistics`

2. **Test Login Page:**
   Visit: `http://localhost/MOA-Wifi/login.html`

3. **Test Admin Interface:**
   Visit: `http://localhost/MOA-Wifi/admin.html`

4. **Test PMS Integration:**
   Try logging in with test credentials (see `pms_config_template.php`)

## Configuration

### Mews PMS Integration

The system integrates directly with Mews PMS using your existing MewsConnector. The integration includes:

1. **Real-time Guest Validation:**
   - Validates room number + surname against live Mews reservations
   - Checks for active reservations intersecting with today's date
   - Supports multiple Mews environments (demo, cert, prod)

2. **Automatic Room Management:**
   - Retrieves current reservations from Mews
   - Maps Mews resource IDs to room numbers
   - Handles customer name matching logic

3. **Fallback System:**
   - Includes test data for development
   - Graceful handling when Mews API is unavailable
   - Detailed error logging for troubleshooting

### Device Limits

- Maximum 3 devices per room can use fast WiFi
- Fast WiFi requires skipping room cleaning
- Normal WiFi has no device limit

## File Structure

```
MOA-Wifi/
├── login.html          # Main login page (German/English)
├── alogin.html         # Success page after login
├── flogin.html         # Error page for failed logins
├── redirect.html       # RouterOS redirect page
├── authenticate.php    # Main authentication logic
├── admin.html          # Admin management interface
├── admin_api.php       # API for admin operations
├── config.php          # Database configuration
├── database_setup.sql  # Database structure
└── README.md          # This file
```

## RouterOS Variables

The system uses these RouterOS variables:

- `$(username)` - Room number (from form)
- `$(radius1)` - Guest surname
- `$(radius2)` - WiFi speed preference ('fast' or 'normal')
- `$(link-orig)` - Original requested URL
- `$(mac)` - Client MAC address (auto-detected)

## Security Considerations

1. **Database Security:**
   - Change default database credentials
   - Use strong passwords
   - Limit database user permissions

2. **Admin Access:**
   - Add authentication to `admin.html`
   - Use HTTPS in production
   - Implement session management

3. **Input Validation:**
   - All user inputs are validated
   - SQL injection prevention with prepared statements
   - XSS protection with proper escaping

## Troubleshooting

### Common Issues

1. **Database Connection Failed:**
   - Check MySQL service is running
   - Verify credentials in `config.php`
   - Ensure database exists

2. **MAC Address Not Detected:**
   - Check RouterOS hotspot configuration
   - Verify PHP `$_SERVER` variables
   - Test with mock MAC generation

3. **Authentication Fails:**
   - Check reservation data in database
   - Verify room number format
   - Check guest surname spelling

### Debug Mode

Enable PHP error reporting for debugging:

```php
// Add to the top of authenticate.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

## Production Deployment

1. **Security Hardening:**
   - Disable PHP error display
   - Enable HTTPS
   - Add admin authentication
   - Configure firewall rules

2. **Performance:**
   - Enable PHP OPcache
   - Configure database indexes
   - Set up database backups

3. **Monitoring:**
   - Log authentication attempts
   - Monitor device counts
   - Track cleaning skip requests

## Support

For technical support or questions:
- Check the troubleshooting section
- Review PHP error logs
- Test with sample data

## License

This system is designed for MOA Hotel internal use.