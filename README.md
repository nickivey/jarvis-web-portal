# JARVIS Web Portal (PHP)

Futuristic blue/black command-center portal with a **hex shield** logo, REST core, Slack messaging, and timestamped auditing.

## Quick start

1. Set env vars (minimum). Tip: copy `slack_service/.env.example` to your own env file or export variables in your shell.

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
* `/public/register.php`
* `/public/login.php`
* `/public/home.php`
* `/public/siri.php`
---

Automated setup script

You can use the included `scripts/setup.sh` to automate common setup tasks: create the database and user (if the `mysql` client is installed), import `sql/schema.sql`, generate a `JWT_SECRET` and start the PHP built-in server.

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
* The script will append a generated `JWT_SECRET` to `.env` if absent.
* Installing the MySQL client requires `apt` and appropriate permissions; the script will try `default-mysql-client` then `mariadb-client`.
* You can still run the SQL in `sql/schema.sql` manually if you prefer.
## REST endpoints

### Auth

* `POST /api/auth/register` JSON: `{ "username": "nick", "email": "nick@example.com", "password": "...", "phone_e164": "+1555..." }`
* `POST /api/auth/login` JSON: `{ "username": "nick", "password": "..." }` → `{ token }`

### User

* `GET /api/me` (Bearer JWT)

### Command center

* `POST /api/command` (Bearer JWT) JSON: `{ "text": "briefing" }`

### Slack messaging

* `POST /api/messages` (Bearer JWT) JSON: `{ "message": "hi", "channel": "C..." }`

### Location

* `POST /api/location` (Bearer JWT) JSON: `{ "lat": 40.0, "lon": -74.0, "accuracy": 20 }`

### Instagram (Basic Display)

* Connect: visit `/public/preferences.php` and click **Connect Instagram**.
* `POST /api/instagram/check` (Bearer JWT) triggers a media update check and writes notifications/audit events.

Notes:
* Basic Display supports **user profile + media** only. **Stories are not available**.

## MySQL schema

Run `sql/schema.sql` in your MySQL DB before first use.

## Google Sign-in

- Create an **OAuth 2.0 Client ID (Web application)** in the Google Cloud Console: https://console.cloud.google.com/apis/credentials
- Add an **Authorized redirect URI**: `SITE_URL/public/google_callback.php` (or set `GOOGLE_REDIRECT_URI` in `env`)
- Set env variables `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` (see `env` file)
- Visit `/public/login.php` or `/` (the register page also shows a **Sign up with Google** button when Google is configured). Click **Sign in with Google** / **Sign up with Google** — Jarvis will create a user if the email doesn't exist, mark the email as verified, and store OAuth tokens in `oauth_tokens`.
- You can also link or unlink your Google account from `/public/preferences.php` after signing in; tokens will be stored in `oauth_tokens` and disconnect removes them.

## Voice input & output

- The portal now supports **voice input** (browser Speech Recognition) and **voice output** (TTS).
- On `/public/home.php` there's a microphone button in the JARVIS Chat panel. Click to start/stop voice input; recognized speech populates the message box.
- The app requests **Notification** permission (for browser notifications) and **Microphone** permission when you use voice input.
- JARVIS command responses (via `POST /api/command`) are spoken aloud automatically when "Speak responses" is enabled.

Server-side TTS

- A simple server-side endpoint at `/public/tts.php` proxies a TTS service and returns an MP3 audio stream. For production, consider installing a robust PHP TTS library or using a cloud TTS provider.
- Example libraries (optional): `stichoza/google-tts-php` (Google Translate TTS wrapper) or official cloud SDKs (Google Cloud Text-to-Speech, Amazon Polly). After installing, update `public/tts.php` to use the library for higher quality and auth support.

## Device registration (for iOS / mobile apps)

- Mobile apps should register themselves with the web API after the user signs in. Endpoints:
  - `POST /api/devices` (Bearer JWT) JSON: `{ "device_uuid": "<uuid>", "platform": "ios", "push_token": "<apns_token>", "push_provider": "apns", "metadata": { } }` → `{ device_id }
  - `GET /api/devices` (Bearer JWT) → `{ devices: [...] }`
  - `POST /api/devices/:id/location` (Bearer JWT) JSON: `{ "lat": 40.0, "lon": -71.0, "accuracy": 12 }` → `{ ok:true }`
  - `DELETE /api/devices/:id` (Bearer JWT) → `{ ok:true }`

- The server stores device tokens in `devices` and records device locations in `location_logs` (with `source='device'`). Mobile apps may periodically send `location` updates and register their push token to receive notifications tied to the user's profile.

- The web UI at `/public/preferences.php` now lists registered devices and allows disconnecting them.
