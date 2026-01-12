# Background Jobs & Photo Reprocessing

This project includes a lightweight background job queue to process tasks such as photo thumbnail generation and EXIF/GPS extraction. When a photo is uploaded, a `photo_reprocess` job is enqueued and the worker processes it.

## Commands

- Run one job: `php scripts/photo_worker.php process_once`
- Run continuously: `php scripts/photo_worker.php run`
- Batch reprocess existing photos: `php scripts/photo_reprocess.php --limit=100`

## Systemd Service (recommended)

Create `/etc/systemd/system/jarvis-photo-worker.service`:

```
[Unit]
Description=JARVIS Photo Worker
After=network.target

[Service]
Type=simple
WorkingDirectory=/srv/jarvis-web-portal
ExecStart=/usr/bin/php scripts/photo_worker.php run
Restart=always
RestartSec=5
Environment=PHP_MEMORY_LIMIT=256M
# Optional: set env for DB and app
Environment=DB_DSN=mysql:host=127.0.0.1;dbname=jarvis
Environment=DB_USER=jarvis
Environment=DB_PASS=secret

[Install]
WantedBy=multi-user.target
```

Enable and start:

```
sudo systemctl daemon-reload
sudo systemctl enable jarvis-photo-worker
sudo systemctl start jarvis-photo-worker
sudo systemctl status jarvis-photo-worker
```

Logs can be viewed via `journalctl -u jarvis-photo-worker -f`.

## Cron Alternative

Use cron with a small sleep to process jobs periodically:

```
* * * * * cd /srv/jarvis-web-portal && /usr/bin/php scripts/photo_worker.php process_once >/var/log/jarvis_worker.log 2>&1
```

This runs a single job every minute. For higher throughput, run `run` mode under a supervisor like `systemd`.

## Troubleshooting

- Verify DB connectivity: `php scripts/debug_enqueue.php`
- Inspect recent jobs: `SELECT id,type,status,available_at,created_at FROM jobs ORDER BY id DESC LIMIT 20;`
- Re-run a stuck job: update `status='pending'` and clear `available_at`.
