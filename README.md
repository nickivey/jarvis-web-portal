# JARVIS Web Portal

Modern blue/black command center with REST APIs, local channels, photo uploads, background jobs, and rich auditing.

## Quick Start

1. Configure environment. You can export variables or use `.env`.

```bash
export SITE_URL="http://localhost:8000"
export MAIL_FROM="jarvis@localhost"

export SLACK_BOT_TOKEN="xoxb-..."
export SLACK_CHANNEL_ID="C123456"

export DB_HOST="127.0.0.1"
export DB_NAME="jarvis"
export DB_USER="root"
export DB_PASS="password"

# JWT (required for REST auth)
export JWT_SECRET="change_me_to_a_long_random_string"
export JWT_ISSUER="jarvis"

# Optional SMS (Twilio)# You can set Twilio API keys in the DB (preferred) or via env vars. Use `scripts/set-secret.php` to write to DB:
#   php scripts/set-secret.php TWILIO_SID "AC..."
#   php scripts/set-secret.php TWILIO_AUTH_TOKEN "..."
#   php scripts/set-secret.php TWILIO_FROM_NUMBER "+15551234567"
# The app will prefer DB settings but falls back to env vars if DB keys are not present.export TWILIO_SID="AC..."
export TWILIO_AUTH_TOKEN="..."
export TWILIO_FROM_NUMBER="+15551234567"

# Instagram Basic Display (media updates)
export INSTAGRAM_CLIENT_ID="..."
export INSTAGRAM_CLIENT_SECRET="..."
# Optional override; otherwise Jarvis uses SITE_URL/public/instagram_callback.php
# export INSTAGRAM_REDIRECT_URI="https://yourdomain.com/public/instagram_callback.php"
```

2. Run:

```bash
cd slack_service
php -S localhost:8000
```

3. Open:
   - `/public/register.php`
   - `/public/login.php` (includes “Sign in as Demo”)
   - `/public/home.php`
   - `/public/siri.php`
---

### Automated Setup

Use `scripts/setup.sh` to quickly create the database, apply schema, generate a JWT secret, and start the PHP server.

Usage:
```bash
# basic: generates JWT (if missing) and starts server
./scripts/setup.sh

# attempt to install mysql client first (requires apt and root/sudo)
./scripts/setup.sh --install-mysql

# run composer install if you have a PHP project with composer.json
./scripts/setup.sh --composer
```

Notes:
- Appends a generated `JWT_SECRET` to `.env` if missing
- Tries `default-mysql-client` then `mariadb-client` when `--install-mysql`
- You can always run `sql/schema.sql` manually

### Admin UI

Visit `/public/admin.php` as an admin to manage settings and secrets.

Create or promote an admin:

```bash
php scripts/create-admin.php <email> [<display_name>]
```

The Admin UI lists, adds, updates, and deletes `settings` used for secrets (e.g., `SENDGRID_API_KEY`, `TWILIO_SID`).

Security note: values are editable for convenience; avoid storing sensitive secrets in plain text and rotate any leaked keys.

## Email (SendGrid)

JARVIS uses SendGrid for email delivery (account confirmation, password resets, etc.). To enable email:

1. **Get a SendGrid API Key** from [SendGrid](https://app.sendgrid.com/settings/api_keys)
2. **Set the API key** in the database:
   ```bash
   php scripts/set-secret.php SENDGRID_API_KEY "SG.your-api-key-here"
   ```
3. **Verify your sender email/domain** in SendGrid:
   - Single Sender: [SendGrid Sender Authentication](https://app.sendgrid.com/settings/sender_auth/senders)
   - Domain Authentication (recommended): [Domain Settings](https://app.sendgrid.com/settings/sender_auth)
4. **Update MAIL_FROM** in the `env` file to match your verified email:
   ```bash
   MAIL_FROM="jarvis@yourdomain.com"
   ```
5. **Test email delivery**:
   ```bash
   php scripts/test-email.php your-email@example.com
   ```

Important: SendGrid requires sender verification. See [SENDGRID_SETUP.md](SENDGRID_SETUP.md) for troubleshooting.

## Location & Weather

- Browser location logging is enabled per-user in **Preferences** (toggle "Enable browser location logging"). The browser will send location to `/api/location` when visiting the portal and will also include location at sign-in if you allow the browser to share location.
- Location history is stored in `location_logs` and is visible from the Home dashboard (map + recent entries) and the full Location History page (`/public/location_history.php`).
- For local weather, set an OpenWeather API key in the DB or env:

```bash
php scripts/set-secret.php OPENWEATHER_API_KEY "<your-key>"
```

The app fetches weather for the most recent location and shows a summary on Home. If `OPENWEATHER_API_KEY` is not set, you may configure `OPENWEATHER_API_KEY_DEFAULT`; otherwise Jarvis returns a demo/fallback.
## REST Endpoints

### Auth

* `POST /api/auth/register` JSON: `{ "username": "nick", "email": "nick@example.com", "password": "...", "phone_e164": "+1555..." }`
* `POST /api/auth/login` JSON: `{ "email": "nick@example.com", "password": "..." }` → `{ token }`

### User

* `GET /api/me` (Bearer JWT)

### Command center

* `POST /api/command` (Bearer JWT) JSON: `{ "text": "briefing" }`

### Photos & iOS Upload

* Guide: `docs/ios-photo-upload.md` — step-by-step Shortcuts instructions to upload photos from iOS to JARVIS. See also `public/ios_photos.php` for an in-app setup page (login required).

* Photos: `/public/photos.php` includes a map & timeline for photos with EXIF GPS data. Reprocess existing photos with `php scripts/photo_reprocess.php` to create thumbnails and extract EXIF where missing.

### Channels (Local)

* `POST /api/messages` with JSON `{ "message": "hello #project", "channel": "local:rhats", "provider": "local" }` will store a local channel message (tags and mentions parsed automatically). Mentions using `@username` will create an in-app notification for the mentioned user (best-effort). Use `GET /api/messages?channel=local:rhats` to list recent messages and `?tag=project` to filter by hashtag.

### Slack Messaging

* `POST /api/messages` (Bearer JWT) JSON: `{ "message": "hi", "channel": "C..." }`
Slack configuration can be set in the database (preferred) or via environment variables. The settings you can set in the DB are:

- `SLACK_APP_TOKEN` (xapp-*) — app-level token for Socket Mode / app-level operations
- `SLACK_BOT_TOKEN` (xoxb-*) — bot token for posting as the app
- `SLACK_CHANNEL_ID` — default channel id
- `SLACK_APP_ID`, `SLACK_CLIENT_ID`, `SLACK_CLIENT_SECRET`, `SLACK_SIGNING_SECRET` — OAuth / signing settings

Use the CLI to seed secrets into the DB (recommended):

```bash
php scripts/set-secret.php SLACK_APP_TOKEN "xapp-..."
php scripts/set-secret.php SLACK_APP_ID "A0A..."
php scripts/set-secret.php SLACK_CLIENT_ID "..."
php scripts/set-secret.php SLACK_CLIENT_SECRET "..."
php scripts/set-secret.php SLACK_SIGNING_SECRET "..."
php scripts/set-secret.php SLACK_CHANNEL_ID "C..."
```

These settings are visible and editable in the Admin UI (`/admin.php`) for convenience.
### Location

* `POST /api/location` (Bearer JWT) JSON: `{ "lat": 40.0, "lon": -74.0, "accuracy": 20 }`

### Instagram (Basic Display)

* Connect: visit `/public/preferences.php` and click **Connect Instagram**.
* `POST /api/instagram/check` (Bearer JWT) triggers a media update check and writes notifications/audit events.

Notes:
* Basic Display supports **user profile + media** only. **Stories are not available**.

## Database Schema

Initialize the database, then (optionally) restore production backups.

1) Create the database and apply the schema:

```bash
# create DB (if not already created)
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"

# apply schema
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/schema.sql
```

2) Restore production backups (after schema):

- Full DB backup: pick a file from [backups/](backups/), e.g. `db_backup_20260112T162423Z.sql`.

```bash
# import full DB backup (schema + data)
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backups/db_backup_20260112T162423Z.sql
```

- Users-only backup: [backups/users_backup_20260112T105228Z.sql](backups/users_backup_20260112T105228Z.sql) can be applied to seed user accounts without touching other tables.

```bash
# import users-only backup
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < backups/users_backup_20260112T105228Z.sql
```

3) Alternative example datasets:

- Production-like example: [sql/examples/jarvis_prod_db_with_users.sql](sql/examples/jarvis_prod_db_with_users.sql)
- Minimal sample: [sql/examples/jarvis_sample_db.sql](sql/examples/jarvis_sample_db.sql)

```bash
# import production-like example
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/examples/jarvis_prod_db_with_users.sql

# or import minimal sample
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/examples/jarvis_sample_db.sql
```

Verification:

```bash
# list users to verify import
php scripts/list-users.php
```

Notes:
- Restoring a full production backup may overwrite existing data. Point `DB_NAME` at a fresh or intended database.
- If backups include schema statements, applying them after `sql/schema.sql` is safe; duplicate "create table" statements will typically be ignored if the table already exists.
- You can also generate a local example DB via [scripts/generate-example-db.sh](scripts/generate-example-db.sh).

## Google Sign-in

- Create an **OAuth 2.0 Client ID (Web application)** in the Google Cloud Console: https://console.cloud.google.com/apis/credentials
- Add an **Authorized redirect URI**: `SITE_URL/public/google_callback.php` (or set `GOOGLE_REDIRECT_URI` in `env`)
- Set env variables `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` (see `env` file)
- Visit `/public/login.php` or `/` (the register page also shows a **Sign up with Google** button when Google is configured). Click **Sign in with Google** / **Sign up with Google** — Jarvis will create a user if the email doesn't exist, mark the email as verified, and store OAuth tokens in `oauth_tokens`.
- You can also link or unlink your Google account from `/public/preferences.php` after signing in; tokens will be stored in `oauth_tokens` and disconnect removes them.

## Voice: Input & Output

- The portal now supports **voice input** (browser Speech Recognition) and **voice output** (TTS).
- On `/public/home.php` there's a microphone button in the JARVIS Chat panel. Click to start/stop voice input; recognized speech populates the message box.
- The app requests **Notification** permission (for browser notifications) and **Microphone** permission when you use voice input. The "Request access" CTA will request microphone, camera, geolocation, and notification permissions in a single gesture for convenience.
- JARVIS command responses (via `POST /api/command`) are spoken aloud automatically when "Speak responses" is enabled.

### Server-side TTS

- A simple server-side endpoint at `/tts.php` proxies a TTS service and returns an MP3 audio stream. For production, consider installing a robust PHP TTS library or using a cloud TTS provider.
- Example libraries (optional): `stichoza/google-tts-php` (Google Translate TTS wrapper) or official cloud SDKs (Google Cloud Text-to-Speech, Amazon Polly). After installing, update `/tts.php` to use the library for higher quality and auth support.

### Voice Recordings & Analysis

- The portal now optionally **captures and stores raw audio** for every voice input. When users use the microphone, the client records the audio (MediaRecorder) and uploads it to `/api/voice` alongside the recognized transcript and metadata. Recordings are stored under `storage/voice/<user_id>/` and indexed in the `voice_inputs` table for later analysis.
- A lightweight 'pnut' log table (`pnut_logs`) stores payloads for offline/deep analysis workflows.
- Admins and engineers should be mindful of privacy and retention. Audio is stored in the project `storage/voice` directory (not publicly exposed). Use the authenticated download endpoint `/api/voice/:id/download` to fetch recordings as needed.
- To audit or inspect voice inputs, use `GET /api/voice?limit=20` (authenticated) to list recent recordings for the current user.
- **Important**: Ensure your deployment has appropriate access controls and retention policies before enabling voice retention in production.

## End-to-End Tests (Playwright)

- A minimal Playwright test scaffold is included to smoke test the Home permission flow and a simple voice command. To run locally:

```bash
# install deps
npm install
# run e2e tests
npm run test:e2e
```

- The test will create a test user using `scripts/create-e2e-user.php` (username `e2e_bot`, password `password`) and then run a simple smoke test that logs in and verifies chat and notification controls.

## Devices (iOS / Mobile)

- Mobile apps should register themselves with the web API after the user signs in. Endpoints:
   - `POST /api/devices` (Bearer JWT) JSON: `{ "uuid": "<uuid>", "platform": "ios", "push_token": "<apns_token>", "push_provider": "apns" }` → `{ id }
  - `GET /api/devices` (Bearer JWT) → `{ devices: [...] }`
  - `POST /api/devices/:id/location` (Bearer JWT) JSON: `{ "lat": 40.0, "lon": -71.0, "accuracy": 12 }` → `{ ok:true }`
  - `DELETE /api/devices/:id` (Bearer JWT) → `{ ok:true }`

- The server stores device tokens in `devices` and records device locations in `location_logs` (with `source='device'`). Mobile apps may periodically send `location` updates and register their push token to receive notifications tied to the user's profile.

- The web UI at `/public/preferences.php` now lists registered devices and allows disconnecting them.
