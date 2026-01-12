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

# Optional SMS (Twilio)
export TWILIO_SID="AC..."
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
