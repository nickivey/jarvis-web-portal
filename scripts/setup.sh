#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"

# Load .env if present
if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

DB_ROOT_USER="${DB_ROOT_USER:-root}"
DB_ROOT_PASS="${DB_ROOT_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-jarvis}"
DB_USER="${DB_USER:-jarvis}"
DB_PASS="${DB_PASS:-password}"
SITE_URL="${SITE_URL:-http://localhost:8000}"
JWT_SECRET="${JWT_SECRET:-}"

# Simple args
INSTALL_MYSQL=0
RUN_COMPOSER=0
for arg in "$@"; do
  case "$arg" in
    --install-mysql) INSTALL_MYSQL=1; shift ;;
    --composer) RUN_COMPOSER=1; shift ;;
    *) shift ;;
  esac
done

# Ensure required PHP binary (prefer one with pdo_mysql)
PHP_BIN=""
for p in php php8.3 php8.2 php8.1 php8.0; do
  if command -v "$p" >/dev/null 2>&1; then
    # Try to detect pdo_mysql for this binary
    if "$p" -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' >/dev/null 2>&1; then
      PHP_BIN="$(command -v "$p")"
      break
    fi
  fi
done
if [ -z "$PHP_BIN" ]; then
  echo "No PHP binary with pdo_mysql found; the app will still run but DB access may fail. Install php-mysql for your PHP version to enable DB support."
  if command -v php >/dev/null 2>&1; then
    PHP_BIN=$(command -v php)
  else
    echo "php not found on PATH. Please install PHP CLI and try again."; exit 1;
  fi
else
  echo "Using PHP binary: $PHP_BIN"
fi

# Optionally attempt to install mysql client
if [ "$INSTALL_MYSQL" -eq 1 ]; then
  echo "--install-mysql requested; attempting to install MySQL client via apt (requires root/sudo)."
  if command -v apt >/dev/null 2>&1; then
    if command -v sudo >/dev/null 2>&1; then
      SUDO=sudo
    else
      SUDO=
    fi
    echo "Running: $SUDO apt update && $SUDO apt install -y default-mysql-client || $SUDO apt install -y mariadb-client"
    set +e
    $SUDO apt update -y
    $SUDO apt install -y default-mysql-client || $SUDO apt install -y mariadb-client
    set -e
  else
    echo "apt not available; cannot install mysql client automatically."
  fi
fi

# Optionally check mysql client
if ! command -v mysql >/dev/null 2>&1; then
  echo "mysql client not found; skipping DB creation and schema import. Install the mysql client (or mariadb-client) to enable DB setup."
else
  echo "Using mysql client to create DB and user (host=$DB_HOST, db=$DB_NAME, user=$DB_USER)"
  mysql_cmd=(mysql -h"$DB_HOST" -u"$DB_ROOT_USER")
  if [ -n "$DB_ROOT_PASS" ]; then mysql_cmd+=(-p"$DB_ROOT_PASS"); fi

  # Try TCP connection first
  if "${mysql_cmd[@]}" -e "SELECT 1" >/dev/null 2>&1; then
    echo "Connected to MySQL via TCP as $DB_ROOT_USER."
    if ! "${mysql_cmd[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" >/dev/null 2>&1; then
      echo "DB creation or grant failed over TCP; check credentials. Will try socket-based root next."
    fi

    if [ -f sql/schema.sql ]; then
      echo "Importing schema.sql into $DB_NAME over TCP..."
      if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/schema.sql >/dev/null 2>&1; then
        echo "Schema import failed over TCP; will attempt socket/root import."
        if sudo mysql -e "USE \`$DB_NAME\`;" >/dev/null 2>&1; then
          echo "Importing schema.sql via socket as root..."
          sudo mysql "$DB_NAME" < sql/schema.sql || echo "Schema import via socket failed (you may need to run it manually)."
        else
          echo "Socket/root access failed; skipping schema import."
        fi
      else
        echo "Schema import successful over TCP."
      fi

      # Run optional migrations (ensure geocache exists for reverse geocoding)
      if [ -f scripts/migrate_add_geocache.php ]; then
        echo "Ensuring location geocache table exists (scripts/migrate_add_geocache.php)..."
        php scripts/migrate_add_geocache.php || echo "Geocache migration failed; you can run scripts/migrate_add_geocache.php manually."
      fi

    else
      echo "sql/schema.sql not found; skipping import."
    fi

  else
    echo "TCP connection as $DB_ROOT_USER failed; trying local socket via sudo mysql."
    if sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
      echo "Connected to MySQL via local socket as root."
      sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" || echo "DB creation or grant failed via socket; check mysql root auth."
      if [ -f sql/schema.sql ]; then
        echo "Importing schema.sql via socket..."
        sudo mysql "$DB_NAME" < sql/schema.sql || echo "Schema import failed via socket (you may need to run it manually)."
      else
        echo "sql/schema.sql not found; skipping import."
      fi
    else
      echo "Unable to connect to MySQL via TCP or socket; skipping DB creation and schema import."
    fi
  fi
fi

# Run composer if requested and available
if [ "$RUN_COMPOSER" -eq 1 ] || [ -f composer.json ]; then
  if command -v composer >/dev/null 2>&1; then
    echo "Running composer install..."
    composer install --no-interaction --prefer-dist || echo "Composer install failed."
  else
    echo "composer not found; skipping composer install. Install Composer or run 'composer install' manually."
  fi
fi

if [ -z "$JWT_SECRET" ]; then
  if command -v openssl >/dev/null 2>&1; then
    JWT_SECRET="$(openssl rand -hex 32)"
  else
    JWT_SECRET="$(head -c32 /dev/urandom | od -A n -t x1 | tr -d ' \n')"
  fi
  echo "Generated JWT_SECRET. Appending to .env (or creating .env)."
  if [ -f .env ]; then
    echo "JWT_SECRET=\"$JWT_SECRET\"" >> .env
  else
    echo "JWT_SECRET=\"$JWT_SECRET\"" > .env
  fi
fi

# Start PHP built-in server serving public/ as docroot
hostport="${SITE_URL#http://}"
hostport="${hostport#https://}"
hostport="${hostport%%/*}"
host="${hostport%%:*}"
port="${hostport##*:}"
if [ "$port" = "$host" ]; then port=8000; fi

logfile="/tmp/jarvis-php-server.log"
pidfile="/tmp/jarvis-php-server.pid"

if [ -f "$pidfile" ]; then
  oldpid="$(cat "$pidfile")"
  if [ -n "$oldpid" ] && kill -0 "$oldpid" 2>/dev/null; then
    echo "Existing PHP server appears to be running (PID $oldpid). Stop it first if you want a fresh start." 
  fi
fi

echo "Starting PHP server at http://$host:$port (docroot: public/)"
php -S "${host}:${port}" -t public > "$logfile" 2>&1 & echo $! > "$pidfile"
sleep 1
if command -v curl >/dev/null 2>&1; then
  echo "Checking server response..."
  if curl -fsS "$SITE_URL/" >/dev/null 2>&1; then
    echo "Server responding at $SITE_URL"
  else
    echo "Server did not respond; tailing log for errors"
    tail -n 50 "$logfile" || true
  fi
else
  echo "curl not installed; cannot health-check server. Logs are at $logfile"
fi

echo "Done. Logs: $logfile  PID file: $pidfile"
