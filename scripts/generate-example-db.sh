#!/usr/bin/env bash
# Generate an example DB export (schema-only) and zip it for inclusion in the repo.
# Usage: ./scripts/generate-example-db.sh

set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(cd "$SCRIPT_DIR/.." && pwd)
OUT_DIR="$ROOT_DIR/sql"
OUT_SQL="$OUT_DIR/example_db_schema.sql"
OUT_ZIP="$OUT_DIR/example_db.zip"

# Prefer environment vars, otherwise try falling back to defaults from db.php (no secrets parsing here)
DB_HOST=${DB_HOST:-localhost}
DB_NAME=${DB_NAME:-nickive2_jarvisp}
DB_USER=${DB_USER:-nickive2_jarvisp}
DB_PASS=${DB_PASS:-}

mkdir -p "$OUT_DIR"

echo "Generating schema-only SQL for database: $DB_NAME (host: $DB_HOST)"

if command -v mysqldump >/dev/null 2>&1; then
  if [ -z "$DB_PASS" ]; then
    mysqldump -h "$DB_HOST" -u "$DB_USER" --no-data --skip-comments --routines --triggers "$DB_NAME" > "$OUT_SQL"
  else
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" --no-data --skip-comments --routines --triggers "$DB_NAME" > "$OUT_SQL"
  fi
  echo "Schema written to $OUT_SQL"
else
  echo "mysqldump not available — falling back to bundled schema.sql"
  cp "$ROOT_DIR/sql/schema.sql" "$OUT_SQL"
fi

# Zip it
rm -f "$OUT_ZIP"
zip -j "$OUT_ZIP" "$OUT_SQL" >/dev/null 2>&1 || true
if [ -f "$OUT_ZIP" ]; then
  echo "Zipped schema to $OUT_ZIP"
else
  echo "Failed to create zip, but schema is available at $OUT_SQL"
fi

echo "Done. Commit $OUT_SQL (and optionally $OUT_ZIP) if you want an example DB in the repo."