# Demo Location History Setup

This package contains demo location history data and import scripts for JARVIS demo users.

## Overview

The demo location history includes:
- **12 sample locations** across Orlando, Florida area
- **Realistic sources**: browser and device locations
- **Varied accuracy levels**: 7m to 100m
- **Timeline spanning**: 10 days to present
- **Real-world locations**: Home, office, shopping, parks, beach, airport

## Quick Start

### Option 1: PHP Import Script (Recommended)

The easiest way to import demo locations on your server:

```bash
# Import to demo user (auto-detected)
php scripts/import_demo_locations.php

# Import to specific user by ID
php scripts/import_demo_locations.php --user-id=47

# Import to specific user by username
php scripts/import_demo_locations.php --user=demo

# Clear existing locations before importing
php scripts/import_demo_locations.php --clear

# Import custom number of locations
php scripts/import_demo_locations.php --count=5
```

**Output Example:**
```
📍 Importing location history for user #47 (demo / demo@example.com)
✓ Imported 12 location entries
📊 Total locations for this user: 12
```

### Option 2: Direct SQL Import

If you prefer to import directly via MySQL:

```bash
# On your server
mysql -h YOUR_HOST -u YOUR_USER -p YOUR_DATABASE < sql/demo_location_history.sql

# Or pipe directly
cat sql/demo_location_history.sql | mysql -u YOUR_USER -p YOUR_DATABASE
```

**Note:** You may need to edit the SQL file to change `user_id = 1` to match your actual demo user ID.

## Finding Your Demo User ID

```bash
php scripts/list-users.php | grep demo
```

Or via SQL:

```sql
SELECT id, username, email FROM users WHERE username='demo' OR email='demo@example.com';
```

## Location Data

### Sample Coordinates (Orlando, FL Area)

| Location | Lat | Lon | Accuracy | Source |
|----------|-----|-----|----------|--------|
| Home | 28.5383 | -81.3792 | 7-15m | browser |
| Office (Downtown) | 28.5421 | -81.3723 | 9-12m | browser |
| Coffee Shop (Winter Park) | 28.5945 | -81.3562 | 8m | browser |
| Shopping (Millenia) | 28.5166 | -81.3836 | 20m | browser |
| Park (Lake Eustis) | 28.7452 | -81.7365 | 25m | device |
| Beach (Daytona) | 29.2108 | -80.9401 | 35m | device |
| Theme Park Area | 28.4756 | -81.4670 | 50m | browser |
| Airport (MCO) | 28.4312 | -81.3088 | 100m | device |
| Gym (Near Home) | 28.5400 | -81.3810 | 11m | browser |

## Customization

### Edit Location Timeline

To add custom locations or modify the timeline, edit the SQL or PHP file:

**SQL File** (`sql/demo_location_history.sql`):
```sql
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5383, -81.3792, 15, 'browser', DATE_SUB(NOW(), INTERVAL 5 DAY));
```

**PHP File** (`scripts/import_demo_locations.php`):
Edit the `$locations` array:
```php
$locations = [
    ['lat' => 28.5383, 'lon' => -81.3792, 'accuracy' => 15, 'source' => 'browser', 'days_ago' => 5],
    // Add more entries...
];
```

### Add More Locations

To add more sample locations to the PHP script, simply add entries to the `$locations` array with:
- `lat`: Latitude
- `lon`: Longitude  
- `accuracy`: Accuracy in meters
- `source`: 'browser' or 'device'
- `days_ago`: How many days in the past (can be fractional, e.g., 0.5 for 12 hours)

## Database Schema

The locations are stored in the `location_logs` table:

```sql
CREATE TABLE location_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  lat DOUBLE NOT NULL,
  lon DOUBLE NOT NULL,
  accuracy_m DOUBLE NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'browser',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_loc_user(user_id),
  CONSTRAINT fk_loc_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Verify Import

Check that locations were imported:

```bash
# Via PHP API (requires JWT token)
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  https://your-server.com/api/locations

# Via SQL
mysql YOUR_DATABASE -e "SELECT COUNT(*) as total_locations FROM location_logs WHERE user_id = 47;"
mysql YOUR_DATABASE -e "SELECT id, lat, lon, source, created_at FROM location_logs WHERE user_id = 47 ORDER BY created_at DESC;"
```

## Troubleshooting

### "Demo user not found"

```bash
# List all users to find the correct ID
php scripts/list-users.php

# Then use:
php scripts/import_demo_locations.php --user-id=YOUR_ID
```

### Duplicate locations

If you accidentally import twice, clear and re-import:

```bash
php scripts/import_demo_locations.php --clear
php scripts/import_demo_locations.php
```

### MySQL access issues

If you get connection errors, verify your database credentials:

```bash
# Check your .env or db.php configuration
php -r "require 'db.php'; echo 'DB connection OK';"
```

## Integration with Your Server

Once imported, the location history will be:
- ✅ Visible on the **Home Dashboard** (recent locations map + list)
- ✅ Available in the **Location History page** (`/public/location_history.php`)
- ✅ Accessible via the **REST API** (`/api/locations`)
- ✅ Used for **Weather data** (fetched for most recent location)

## Advanced Usage

### Bulk Import Multiple Users

```bash
for user_id in 1 2 3 4; do
  php scripts/import_demo_locations.php --user-id=$user_id --clear
done
```

### Generate Location History from Photos

If you have photos with EXIF GPS data:

```bash
# Extract EXIF locations and create location_logs entries
php scripts/photo_reprocess.php
```

### Clear All Locations

```bash
mysql YOUR_DATABASE -e "DELETE FROM location_logs WHERE user_id = 47;"
```

## Support

For issues or questions:
1. Check the [Location History documentation](../README.md#location--weather)
2. Review your database logs: `mysql YOUR_DATABASE -e "SHOW ENGINE INNODB STATUS;"`
3. Verify JWT token validity: `php scripts/get-jwt.php`
