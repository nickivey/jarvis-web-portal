# Demo User Location History - Summary & Records

## Overview

Complete demo location history package for JARVIS demo users. Ready to deploy to your production server.

**Created:** January 13, 2026
**Status:** Production Ready ✅

## Files Created

### 📋 Database Files

| File | Size | Purpose |
|------|------|---------|
| `sql/demo_location_history.sql` | 3.0 KB | SQL import file with 12 location records |
| `scripts/import_demo_locations.php` | 5.6 KB | PHP script to import/manage location history |

### 📚 Documentation Files

| File | Purpose |
|------|---------|
| `LOCATION_HISTORY_SETUP.md` | Comprehensive setup guide with all options |
| `DEMO_LOCATIONS_DEPLOYMENT.md` | Quick server deployment reference |
| `DEMO_USER_LOCATION_RECORDS.md` | This file - complete location data |

### 🚀 Deployment Scripts

| File | Purpose |
|------|---------|
| `deploy_demo_locations.sh` | Automated FTP deployment script |

## Demo Location Records

### User: demo (typically ID = 1 or 47)
**Email:** demo@example.com
**Password:** password

### 12 Sample Locations

All locations are in the Orlando, Florida area representing a realistic user journey over 10 days.

#### Location 1: Home
```
Coordinates: 28.5383, -81.3792
Accuracy: 15m
Source: browser
Timestamps: Multiple entries (5 days ago, 1 day ago, 1 hour ago)
Description: Primary residence location, visited multiple times
```

#### Location 2: Downtown Office
```
Coordinates: 28.5421, -81.3723
Accuracy: 9-12m
Source: browser
Timestamp: 4 days ago (morning)
Description: Work location, downtown Orlando
```

#### Location 3: Coffee Shop
```
Coordinates: 28.5945, -81.3562 (Winter Park)
Accuracy: 8m
Source: browser
Timestamp: 3 days ago
Description: Coffee/meeting location
```

#### Location 4: Shopping Center
```
Coordinates: 28.5166, -81.3836 (Millenia)
Accuracy: 20m
Source: browser
Timestamp: 2 days ago
Description: Shopping mall visit
```

#### Location 5: Recreation Park
```
Coordinates: 28.7452, -81.7365 (Lake Eustis)
Accuracy: 25m
Source: device
Timestamp: 8 hours ago
Description: Parks and recreation area
```

#### Location 6: Beach
```
Coordinates: 29.2108, -80.9401 (Daytona Beach)
Accuracy: 35m
Source: device
Timestamp: 8 days ago
Description: Beach visit/travel
```

#### Location 7: Theme Park Area
```
Coordinates: 28.4756, -81.4670 (Universal/Disney proximity)
Accuracy: 50m
Source: browser
Timestamp: 6 days ago
Description: Entertainment venue area
```

#### Location 8: Airport
```
Coordinates: 28.4312, -81.3088 (MCO - Orlando International)
Accuracy: 100m
Source: device
Timestamp: 10 days ago
Description: Airport arrival/departure point
```

#### Location 9: Gym/Fitness
```
Coordinates: 28.5400, -81.3810 (Near home)
Accuracy: 11m
Source: browser
Timestamp: 7 hours ago
Description: Fitness center near residence
```

#### Locations 10-12: Additional Home Visits
```
Same as Location 1 (home): 28.5383, -81.3792
Various times throughout the tracking period
Represents realistic daily routine returns
```

## Data Schema

```sql
CREATE TABLE location_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,           -- Demo user ID
  lat DOUBLE NOT NULL,                        -- Latitude
  lon DOUBLE NOT NULL,                        -- Longitude
  accuracy_m DOUBLE NULL,                     -- Accuracy in meters
  source VARCHAR(32) NOT NULL DEFAULT 'browser',  -- 'browser' or 'device'
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Entry timestamp
  PRIMARY KEY(id),
  KEY ix_loc_user(user_id),
  CONSTRAINT fk_loc_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Total Records

- **Demo User Locations:** 12 entries
- **Date Range:** 10 days (past) to present
- **Geographic Range:** Orlando metro area, Florida
- **Accuracy Range:** 7m to 100m
- **Source Mix:** 8 browser entries, 4 device entries

## Quick Deploy Commands

### Deploy to FTP Server

```bash
# Automated deployment
bash deploy_demo_locations.sh jjj@jarvis.nickivey.com "%)?66AEa3Fw{ijAr}"

# Then on your server:
php scripts/import_demo_locations.php
```

### Direct SQL Import

```bash
# On your production server
mysql -u YOUR_USER -p YOUR_DATABASE < sql/demo_location_history.sql

# Note: Edit sql file first if user_id != 1
sed -i 's/user_id = 1/user_id = 47/g' sql/demo_location_history.sql
```

### PHP Import with Options

```bash
# Import to demo user (auto-detected)
php scripts/import_demo_locations.php

# Import to specific user by ID
php scripts/import_demo_locations.php --user-id=47

# Clear existing and re-import
php scripts/import_demo_locations.php --clear

# Import only 5 locations
php scripts/import_demo_locations.php --count=5
```

## What Users Will See

### 1. Location History Page (`/public/location_history.php`)
- Interactive map showing all 12 locations
- Timeline table with details for each entry
- Individual location focus functionality
- Export/analysis capabilities

### 2. Home Dashboard (`/public/home.php`)
- Recent locations map widget
- Quick location timeline
- Weather for most recent location
- Location-based status information

### 3. REST API (`/api/locations`)
- JSON response with all locations for authorized user
- Includes reverse-geocoded addresses
- Pagination support (limit parameter)
- Full location metadata

## Location Highlights

🏠 **Home Base:** Appears 5 times in the history  
💼 **Work:** Downtown Orlando location  
☕ **Activity:** Coffee shops, shopping, parks, beach, gym  
✈️ **Travel:** Airport recorded 10 days ago  
🗺️ **Geographic Spread:** ~30-50 km coverage (typical daily range)

## Accuracy Notes

| Accuracy | Type | Count |
|----------|------|-------|
| 7-15m | High precision (home, gym, office) | 7 |
| 20-35m | Medium precision (shops, parks, beach) | 3 |
| 50-100m | Low precision (theme park, airport) | 2 |

Perfect for:
- ✅ Testing location features
- ✅ Demonstrating map functionality
- ✅ Weather integration examples
- ✅ Timeline visualization
- ✅ Geofencing setup

## Database Verification

After import, verify with:

```bash
# Count locations
mysql YOUR_DB -e "SELECT COUNT(*) as locations FROM location_logs WHERE user_id = 47;"

# View latest entry
mysql YOUR_DB -e "SELECT id, lat, lon, accuracy_m, source, created_at FROM location_logs WHERE user_id = 47 ORDER BY created_at DESC LIMIT 1;"

# Check date range
mysql YOUR_DB -e "SELECT MIN(created_at) as earliest, MAX(created_at) as latest FROM location_logs WHERE user_id = 47;"
```

Expected output:
```
locations: 12
earliest: 10 days ago
latest: within the last hour
```

## Customization Options

After deployment, you can:

1. **Add more locations** - Edit PHP script or SQL file
2. **Adjust timeline** - Modify `days_ago` values
3. **Change coordinates** - Point to your actual city/area
4. **Adjust accuracy** - Make locations more/less precise
5. **Clear and reload** - Use `--clear` flag to reset

See `LOCATION_HISTORY_SETUP.md` for detailed customization instructions.

## Deployment Checklist

- [ ] Copy files to FTP server or local `/var/www` directory
- [ ] Find your demo user ID: `php scripts/list-users.php`
- [ ] Run import: `php scripts/import_demo_locations.php --user-id=YOUR_ID`
- [ ] Verify count: `mysql DB -e "SELECT COUNT(*) FROM location_logs WHERE user_id=YOUR_ID;"`
- [ ] Test in browser: Log in as demo user
- [ ] Check Location History page: `/public/location_history.php`
- [ ] Verify map shows Orlando locations
- [ ] Check Home dashboard shows recent locations
- [ ] Test REST API: `/api/locations`

## Support & Documentation

- **Full Setup Guide:** `LOCATION_HISTORY_SETUP.md`
- **Server Deploy Guide:** `DEMO_LOCATIONS_DEPLOYMENT.md`
- **Main README:** `README.md` (search "Location & Weather")
- **API Docs:** `docs/openapi.yaml` (search "locations")
- **Database Schema:** `sql/schema.sql` (search "location_logs")

## Technical Details

**Database Table:** `location_logs`
**User ID:** Configurable (default: demo user)
**Records:** 12 complete location entries
**Source Data:** Orlando, Florida metropolitan area
**Accuracy:** 7m to 100m (realistic GPS variance)
**Timeline:** 10 days past to present
**Import Time:** < 1 second

---

✅ **Status:** Ready for Production Deployment  
📅 **Created:** January 13, 2026  
📊 **Total Records:** 12 locations  
🎯 **Target Users:** demo@example.com and any other demo users  
🚀 **Deployment Time:** 5-10 minutes
