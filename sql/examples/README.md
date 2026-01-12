This folder contains a sanitized example database dump for JARVIS intended for local development and testing.

Files:
- `jarvis_sample_db.sql` — Full SQL dump of the `nickive2_jarvisp` database with sensitive keys redacted (SENDGRID, Slack, Twilio, Google, etc.).
- `jarvis_sample_db.zip` — Compressed archive of the SQL dump for easier download/import.

Import:
1. Create a local MySQL database (e.g., `jarvis_example`).
   mysql -u root -p -e "CREATE DATABASE jarvis_example CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
2. Import the dump:
   mysql -u root -p jarvis_example < sql/examples/jarvis_sample_db.sql

Notes:
- The dump is intentionally sanitized; you will need to configure real provider credentials in environment variables or via the Admin settings panel.
- If you want to restore actual (non-production) data, consider seeding it via `scripts/seed-secrets.php` and other seed scripts provided in the project.
- Do NOT commit real secrets to the repository; rotate and set provider keys externally.
