# Uploading Photos from iOS to JARVIS

This document explains how to automatically or manually upload photos from an iPhone/iPad to your JARVIS instance using the iOS Shortcuts app (Personal Automations and Shortcuts). It includes recommended server request formats, examples, and notes about iOS automation limitations and privacy.

## Quick Start

1. **Navigate to Setup Page**: Go to `/ios_upload_setup.php` in your JARVIS portal
2. **Generate a Device Token**: Click "Generate New Token" to create an authentication token
3. **Copy & Configure**: Use the provided token and endpoint URL in your iOS Shortcut
4. **Test Upload**: Run your Shortcut to verify photos upload correctly

## Summary

- Two common approaches:
  - Share Sheet / Shortcut (manual) — user selects one or more photos and runs the Shortcut from the Share sheet.
  - Personal Automation (semi-automatic) — trigger via NFC tag, Back Tap, or manual shortcut run. *Note:* many personal automations (for example, "When I take a photo") still prompt to confirm before running; see Limitations below.
- Shortcut flow sends the image as a multipart/form-data POST to your JARVIS server using a Bearer token for auth.

## Requirements

- A reachable JARVIS server over HTTPS (recommended): e.g. https://jarvis.example.com
- A valid device token (generated via the iOS Upload Setup page or API)
- iOS device with Shortcuts app (built-in) and permission to access Photos

> Tip: Use a browser-accessible, secure URL and HTTPS certificates for reliable uploads. If you plan to upload large image files frequently, test on Wi‑Fi and consider limiting uploads on cellular.

## Web-Based Setup (Recommended)

The easiest way to configure iOS photo uploads is through the web interface:

### Step 1: Access the Setup Page
Navigate to `/ios_upload_setup.php` after logging in to JARVIS.

### Step 2: Generate a Device Token
Click the "Generate New Token" button. This creates a secure, long-lived token specifically for your iOS device.

### Step 3: Copy Your Credentials
The page displays:
- **API Endpoint**: The URL to use in your Shortcut (e.g., `https://your-domain.com/api/photos`)
- **Device Token**: Your authentication token (click to copy)

### Step 4: Configure iOS Shortcut
Follow the step-by-step guide on the setup page to create your Shortcut.

### Step 5: Test & Verify
Run your Shortcut to upload a test photo, then check the Photos gallery to confirm it appears.

## API Reference

### Upload Endpoint
**POST** `/api/photos`

**Headers:**
```
Authorization: Bearer <DEVICE_TOKEN>
Content-Type: multipart/form-data
```

**Form Fields:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | File | Yes | The image file to upload (JPEG, PNG, HEIC) |
| `meta` | JSON String | No | Optional metadata (e.g., `{"source":"ios-shortcut","album":"Vacation"}`) |

**Success Response:**
```json
{
  "ok": true,
  "id": 123,
  "url": "/storage/photos/2/abc123.jpg"
}
```

**Error Response:**
```json
{
  "ok": false,
  "error": "Invalid token"
}
```

### Device Token Management
**POST** `/api/device_tokens` — Create a new device token (requires authenticated session)

**GET** `/api/device_tokens` — List your device tokens

**DELETE** `/api/device_tokens/:id` — Revoke a token

### Photo Retrieval
**GET** `/api/photos` — List all photos with metadata (requires auth)

**GET** `/api/photos/:id/download` — Download full-resolution photo

**GET** `/api/photos/:id/download?thumb=1` — Download thumbnail

## Recommended Server Request

Endpoint (recommended): POST https://YOUR-SITE/api/photos

Notes: This project now includes a `/api/photos` endpoint and a simple gallery UI available at `/public/photos.php`. Uploaded photos are stored under `storage/photos/<user_id>/` and a thumbnail is generated when possible. Use `/api/photos` (GET) to list photos and `/api/photos/:id/download` to fetch the photo or `?thumb=1` for the thumbnail.

Form fields (multipart/form-data):
- file — the image file (required)
- meta — optional JSON string with metadata (e.g. {"source":"ios-shortcut","album":"Summer"})

Headers:
- Authorization: Bearer <JWT>  (or use a per-device upload token; see below)

Device tokens & Install Page:
- You can create a short-lived per-device upload token via `POST /api/device_tokens` (authenticated as the user), or use the web setup page at `/ios_upload_setup.php` to generate and manage tokens easily. Tokens should be used as `Authorization: Bearer <DEVICE_TOKEN>` in the Shortcut.

Response (example):
{
  "ok": true,
  "id": 123,
  "url": "/storage/photos/2/abc123.jpg"
}

> Note: This project doesn't include a `/api/photos` endpoint by default. If you want server-side support, see Implementation Notes below.

## Automation Triggers

JARVIS supports several automation triggers for hands-free photo uploads:

### 🏷️ NFC Tag (Recommended)
Place an NFC sticker near your camera area. Tap your iPhone after taking photos to trigger upload.
- **Pros**: Most reliable, runs without confirmation
- **Setup**: Shortcuts → Automation → Create Personal Automation → NFC

### 👆 Back Tap
Double or triple tap the back of your iPhone to upload recent photos.
- **Pros**: No additional hardware needed
- **Setup**: Settings → Accessibility → Touch → Back Tap

### 🔌 When Connected to Charger
Automatically upload photos when you plug in your iPhone.
- **Pros**: Great for daily backups
- **Setup**: Shortcuts → Automation → When Charger is Connected

### ⏰ Time of Day
Schedule photo uploads at specific times.
- **Pros**: Predictable, low-maintenance
- **Setup**: Shortcuts → Automation → Time of Day

### ❌ "When I Take a Photo" (Not Recommended)
iOS requires confirmation for this trigger, making it impractical.

## Shortcut: Share Sheet (manual) — step by step

1. Open the Shortcuts app and create a new **Shortcut**.
2. Add action: **Select Photos** (toggle "Select Multiple" depending on whether you want to upload multiple photos at once).
3. Add action: **Get File** (pass the selected photos to file output; set "Convert" to "On" if necessary).
4. Add action: **Get Contents of URL**
   - Method: POST
   - URL: https://your-jarvis.example.com/api/photos
   - Request Body: Form
   - Add a field named `file` and set it to the file variable from step 3 (choose the magic variable popup)
   - Add a field `meta` if you want to send JSON metadata (type: Text)
   - Headers: Add a Dictionary with key `Authorization` value `Bearer <YOUR_JWT_TOKEN>` (create a Dictionary action with the header and feed it to "Get Contents of URL" header input)
5. Optionally add **Show Result** or **Quick Look** to inspect the server response.
6. Save the shortcut and use the Share sheet > Shortcuts to run it on selected photos, or tap the shortcut from the Shortcuts app.

## Shortcut: Personal Automation (semi-automatic)

You can create a Personal Automation in Shortcuts to run a shortcut under a trigger. Two commonly used automatic triggers for photo workflows are:

- NFC tag (place a small NFC sticker near your camera area, and tap the phone after taking photos to run the upload without a manual share)
- Back Tap (double or triple tap on the back of the iPhone to run the shortcut and upload the latest photo(s))

Steps (example using Back Tap):
1. Create a Shortcut as above that uploads the most recent photo (use **Get Latest Photos** with limit 1).
2. Save the shortcut and name it (e.g., "Upload Last Photo to Jarvis").
3. Open Settings → Accessibility → Touch → Back Tap and assign the shortcut to Double/Triple Tap.

Important: For personal automations created in the Shortcuts app (Shortcuts → Automation), iOS may require you to disable "Ask Before Running" for some triggers — this is allowed for some triggers (Back Tap, NFC), but other triggers (for example "When I take a photo") will typically prompt for confirmation every time. If fully automatic uploads are required, use NFC/Back Tap or run the Shortcuts manually from the Share sheet or via Siri.

## Example: Create a "Upload last photo" shortcut for Automation

- Action 1: **Get Latest Photos** → Set to 1 photo, most recent
- Action 2: **Get File** (from the photo) — ensure the output is a file
- Action 3: **Dictionary** → Add key `Authorization` = `Bearer <JWT>`
- Action 4: **Get Contents of URL**
  - Method: POST
  - URL: https://your-jarvis.example.com/api/photos
  - Request Body: Form
  - Field `file` = the file from step 2
  - Headers = the dictionary from step 3
- Action 5: **Show Notification** or **Show Result** to confirm upload success.

## Testing with curl

You can test uploads without iOS using curl (replace values):

curl -X POST "https://your-jarvis.example.com/api/photos" \
  -H "Authorization: Bearer <JWT>" \
  -F "file=@/path/to/example.jpg" \
  -F "meta={\"source\":\"curl\",\"note\":\"test\"}"

## iOS automation limitations & privacy notes

- iOS may show an "Ask Before Running" prompt for certain automations. This is controlled by Apple and can change across iOS versions; NFC and Back Tap have historically been effective for hands-free triggering.
- Frequent automatic uploads can consume data and battery — consider limiting uploads to Wi‑Fi only or batching uploads.
- Treat photos as sensitive data. Limit retention, protect the server with HTTPS and JWT auth, and consider storing a minimal derived thumbnail if you don't need full resolution.

## Implementation notes (server-side)

If you want JARVIS to accept, index, and show user-uploaded photos automatically, consider implementing a small endpoint and storage table:

- New DB table `photos` (example):
  - id, user_id, filename, original_filename, metadata_json, created_at
- Storage path: `storage/photos/<user_id>/<filename>`
- Endpoint: `POST /api/photos` (Bearer JWT)
  - Accept multipart `file`, optional `meta` JSON field
  - Save file to storage, insert DB row, return JSON with `id` and `url`
  - Optionally create thumbnails and extract EXIF data (timestamp/location). When EXIF GPS is present, Jarvis creates a `location_logs` entry with source `photo` and adds `metadata.exif_gps` and `metadata.photo_location_id` to the stored metadata.

Security: validate MIME types and file sizes, limit number of uploads per minute, and consider virus scanning for public deployments.

Background reprocessing

- Use `scripts/photo_reprocess.php` to re-run thumbnail generation and EXIF extraction for existing photos; this updates `metadata_json` and creates `location_logs` entries for photos with GPS EXIF.

Gallery map & timeline

- The gallery at `/public/photos.php` includes a map (Leaflet/OpenStreetMap) showing photos with GPS EXIF and a timeline panel listing photos by date. To populate the map, ensure photos have EXIF GPS metadata (extracted at upload or via the reprocess script).

## Advanced ideas

- Offer a pre-built `.shortcut` file or iCloud Link that users can tap to install a ready-made Shortcut.
- Allow photo uploads via email (inbox-address per-user) or third-party integrations (Dropbox, Google Photos) as alternate sources.

## Related Pages

- **iOS Upload Setup**: `/ios_upload_setup.php` — Web-based token generation and setup guide
- **Photo Gallery**: `/public/photos.php` — View all uploaded photos with map, timeline, and grid views
- **Home Dashboard**: `/public/home.php` — Quick access photo card on the main dashboard

## Troubleshooting

### Photos not appearing in gallery
1. Check the Shortcut ran successfully (look for success notification)
2. Verify the device token is valid (regenerate if needed)
3. Ensure the server URL is correct and accessible
4. Check server logs for upload errors

### "Invalid token" error
- Your device token may have been revoked
- Generate a new token from the iOS Upload Setup page
- Update your Shortcut with the new token

### Large photos failing to upload
- iOS may timeout on large uploads over cellular
- Try uploading on Wi-Fi
- Consider enabling "Convert" in the Shortcut to reduce file size

### EXIF location not appearing on map
- Ensure Location Services is enabled for Camera
- Photos must have GPS EXIF data embedded at capture time
- Run `scripts/photo_reprocess.php` to re-extract EXIF from existing photos

---

Last updated: Based on JARVIS photo system improvements including the iOS Upload Setup page, enhanced photo gallery with map/timeline views, and device token authentication.