#!/usr/bin/env bash
# Database + uploads backup to the SMB-mounted external USB drive.
# Run manually first, then schedule with cron (see 00-START-HERE.md Phase 7).
set -Eeuo pipefail

cd /opt/learning

BACKUP_DIR="/mnt/learning-backups"
DATE=$(date +"%Y-%m-%d_%H-%M-%S")
SQL_FILE="$BACKUP_DIR/learning_$DATE.sql.gz"
TMP_SQL="$SQL_FILE.tmp"
UPLOADS_FILE="$BACKUP_DIR/uploads_$DATE.tgz"
TMP_UP="$UPLOADS_FILE.tmp"

if ! mountpoint -q "$BACKUP_DIR"; then
    echo "ERROR: external USB backup share is not mounted at $BACKUP_DIR" >&2
    exit 1
fi

if [ -z "$(docker compose ps -q db)" ]; then
    echo "ERROR: MariaDB container is not running" >&2
    exit 1
fi

cleanup() { rm -f "$TMP_SQL" "$TMP_UP"; }
trap cleanup EXIT

echo "Starting Learning LMS backup at $DATE ..."

# Compressed, transactionally consistent database dump.
docker compose exec -T db sh -c \
  'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" \
   --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip -c > "$TMP_SQL"

gzip -t "$TMP_SQL"
mv "$TMP_SQL" "$SQL_FILE"
sha256sum "$SQL_FILE" > "$SQL_FILE.sha256"

# Uploads are on a bind mount, not in the database volume.
tar czf "$TMP_UP" -C /opt/learning lms-api/uploads
mv "$TMP_UP" "$UPLOADS_FILE"
sha256sum "$UPLOADS_FILE" > "$UPLOADS_FILE.sha256"

# Keep 14 days of copies (both files and their checksums).
find "$BACKUP_DIR" -type f \
  \( -name 'learning_*.sql.gz*' -o -name 'uploads_*.tgz*' \) \
  -mtime +14 -delete

echo "Verifying database backup checksum:"
sha256sum -c "$SQL_FILE.sha256"
echo "Backup complete:"
ls -lh "$SQL_FILE" "$SQL_FILE.sha256" "$UPLOADS_FILE" "$UPLOADS_FILE.sha256"
