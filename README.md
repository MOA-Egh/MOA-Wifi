# MOA Hotel WiFi Management System

Hotel WiFi authentication system integrated with Mews PMS.

## Project Structure

```
MOA-Wifi/
├── public/                 # Web-accessible files (document root)
│   ├── index.php          # Entry point
│   ├── login.html         # Guest login page
│   ├── authenticate.php   # Authentication handler
│   ├── admin.html         # Admin dashboard
│   ├── admin_api.php      # Admin API
│   ├── alogin.html        # Success page
│   ├── flogin.html        # Error page
│   └── assets/
│       └── images/        # Logo and flag images
│
├── src/                    # PHP classes (protected)
│   ├── MewsConnector.php  # Mews API connector
│   └── MewsWifiAuth.php   # WiFi authentication adapter
│
├── config/                 # Configuration (protected)
│   ├── config.php         # Database settings
│   ├── mews_config.php    # Mews API settings
│   └── ini/               # API credentials
│
├── database/              # Database scripts
│   └── setup.sql          # Schema setup
│
└── docs/                  # Documentation
    ├── README.md
    ├── ROUTEROS_SETUP.md
    └── SETUP_GUIDE.md
```

## Setup

1. Configure your web server document root to `public/` folder
2. Copy `config/ini/demo_mews.ini.template` to create environment INI files
3. Update `config/config.php` with database credentials
4. Run `database/setup.sql` to create tables
5. See `docs/SETUP_GUIDE.md` for detailed instructions

## Security

- `src/`, `config/`, and `database/` folders are protected via `.htaccess`
- INI files containing API credentials are outside web root
- Only `public/` folder should be web-accessible
