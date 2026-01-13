# Demo Location History - Server Deployment Guide

## Quick Reference

You now have everything you need to add demo location history to your production server. Here's what's included:

### 📁 Files Created

```
scripts/
├── import_demo_locations.php      # ✨ Main import script (run this on server)
├── list-users.php                 # Find your demo user ID
└── ...

sql/
├── demo_location_history.sql      # Raw SQL import file
└── ...

./ (root)
├── LOCATION_HISTORY_SETUP.md      # Detailed setup documentation
├── deploy_demo_locations.sh       # FTP deployment script
└── DEMO_LOCATIONS_DEPLOYMENT.md   # This file
```

## Server Deployment - 3 Easy Steps

### Step 1: Upload Files to FTP Server

```bash
# From your local workspace:
cd /workspaces/jarvis-web-portal

# Option A: Use the automated deployment script
bash deploy_demo_locations.sh jjj@jarvis.nickivey.com "%)?66AEa3Fw{ijAr}"

# Option B: Manual FTP upload
# Upload these files to your server:
#   - scripts/import_demo_locations.php
#   - sql/demo_location_history.sql
#   - LOCATION_HISTORY_SETUP.md
```

### Step 2: Connect to Your Server

Via SSH or FTP, navigate to your application root:

```bash
# Example: WordPress-style hosting
cd /home/YOUR_DOMAIN/public_html
# or
cd /var/www/html
# or
cd /home/cpanel_user/public_html
```

### Step 3: Run the Import

```bash
# Basic import (auto-detects demo user)
php scripts/import_demo_locations.php

# Or specify a user ID
php scripts/import_demo_locations.php --user-id=47

# Output:
# 📍 Importing location history for user #47 (demo / demo@example.com)
# ✓ Imported 12 location entries
# 📊 Total locations for this user: 12
```

## Verify Import Worked

### Via Web Browser

1. Log in to JARVIS: `https://your-site.com/public/login.php`
   - Username: `demo`
   - Password: `password`

2. Visit **Location History**: `https://your-site.com/public/location_history.php`
   - Should see 12 location entries
   - Map should show Orlando, FL area

3. Check **Home Dashboard**: `https://your-site.com/public/home.php`
   - Recent locations map should be populated
   - Latest weather for most recent location

### Via Database

```bash
# Count locations for demo user (ID 47 or whatever)
mysql YOUR_DATABASE -e "SELECT COUNT(*) as total FROM location_logs WHERE user_id = 47;"

# View location entries
mysql YOUR_DATABASE -e "SELECT id, lat, lon, source, created_at FROM location_logs WHERE user_id = 47 ORDER BY created_at DESC LIMIT 5;"
```

### Via REST API

```bash
# Get JWT token for demo user
php scripts/get-jwt.php demo

# Use token to fetch locations
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  https://your-site.com/api/locations | jq .
```

## Customization on Server

Once uploaded, you can customize the locations before importing:

### Edit PHP Script

```bash
nano scripts/import_demo_locations.php
```

Modify the `$locations` array:
```php
$locations = [
    ['lat' => YOUR_LAT, 'lon' => YOUR_LON, 'accuracy' => 15, 'source' => 'browser', 'days_ago' => 5],
    // Add more...
];
```

### Or Use SQL File

```bash
# Edit the SQL file
nano sql/demo_location_history.sql

# Then import directly
mysql YOUR_DATABASE < sql/demo_location_history.sql
```

## Troubleshooting

### "Demo user not found"

```bash
# Find the correct user ID
php scripts/list-users.php

# Use that ID:
php scripts/import_demo_locations.php --user-id=YOUR_ID
```

### "DB not configured"

The script looks for:
1. `.env` file with database credentials
2. Environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
3. Fallback in `db.php` hardcoded values

Verify your server has database access configured.

### "Locations not showing in UI"

1. Clear browser cache
2. Check JavaScript console for errors
3. Verify JWT token is valid: `php scripts/get-jwt.php`
4. Check database: `SELECT COUNT(*) FROM location_logs WHERE user_id = YOUR_ID;`

## Advanced Usage

### Import for Different User

```bash
# Import to user with ID 5
php scripts/import_demo_locations.php --user-id=5

# Import to user 'nickivey'
php scripts/import_demo_locations.php --user=nickivey
```

### Clear and Re-import

```bash
# Clear existing locations
php scripts/import_demo_locations.php --clear

# Import fresh data
php scripts/import_demo_locations.php
```

### Custom Location Count

```bash
# Only import first 5 locations
php scripts/import_demo_locations.php --count=5

# Import all 12
php scripts/import_demo_locations.php --count=12
```

### Automated Server Script

Create `/home/YOUR_DOMAIN/import_locations.sh`:

```bash
#!/bin/bash
cd /home/YOUR_DOMAIN/public_html
php scripts/import_demo_locations.php --clear
php scripts/import_demo_locations.php
echo "Location history imported at $(date)" >> /var/log/jarvis_imports.log
```

Then:
```bash
chmod +x /home/YOUR_DOMAIN/import_locations.sh
/home/YOUR_DOMAIN/import_locations.sh
```

## Sample Locations Included

The 12 demo locations cover:

- **Home** (28.5383, -81.3792) - 5 entries at various times
- **Office** (28.5421, -81.3723) - Downtown Orlando
- **Coffee Shop** (28.5945, -81.3562) - Winter Park
- **Shopping** (28.5166, -81.3836) - Millenia Mall
- **Park** (28.7452, -81.7365) - Lake Eustis
- **Beach** (29.2108, -80.9401) - Daytona Beach
- **Theme Park** (28.4756, -81.4670) - Universal/Disney area
- **Airport** (28.4312, -81.3088) - MCO
- **Gym** (28.5400, -81.3810) - Near home

All with realistic:
- ✅ Timestamps (10 days to present)
- ✅ Accuracy levels (7m to 100m)
- ✅ Sources (browser and device)

## What Gets Displayed

### In Location History Page
- Interactive map showing all locations
- Timeline table with date/time, coordinates, accuracy, source
- Individual location focus (click to view on map)

### In Home Dashboard
- Recent locations map widget
- Quick location timeline
- Current location (if available)
- Weather for most recent location

### In REST API
```bash
GET /api/locations?limit=200
```

Returns:
```json
{
  "ok": true,
  "locations": [
    {
      "id": 1,
      "user_id": 47,
      "lat": 28.5383,
      "lon": -81.3792,
      "accuracy_m": 15,
      "source": "browser",
      "created_at": "2026-01-08 12:00:00",
      "address": { ... }
    },
    ...
  ]
}
```

## Performance Notes

- Import script: < 1 second
- Database queries: Indexed on `user_id`
- Browser rendering: Smooth with < 200 locations
- Geocaching: Automatic reverse-lookup caching

## Next Steps

1. ✅ Deploy scripts to production FTP server
2. ✅ Run `php scripts/import_demo_locations.php`
3. ✅ Visit location history page and verify
4. ✅ (Optional) Customize locations for your needs
5. ✅ (Optional) Create automated import via cron job

## Support Resources

- Full docs: `LOCATION_HISTORY_SETUP.md`
- API reference: `docs/openapi.yaml` (search "locations")
- Database schema: `sql/schema.sql` (search "location_logs")
- Main README: `README.md` (search "Location & Weather")

---

**Last Updated:** January 13, 2026
**Status:** Ready for Production Deployment ✅
