#!/usr/bin/env bash
# Restore a Learning LMS backup. WARNING: overwrites current database contents.
# Usage: ./restore.sh /mnt/learning-backups/learning_YYYY-MM-DD_HH-MM-SS.sql.gz [uploads_YYYY-MM-DD_HH-MM-SS.tgz]
set -Eeuo pipefail

BACKUP_FILE="${1:-}"
UPLOADS_FILE="${2:-}"

if [[ -z "$BACKUP_FILE" || ! -f "$BACKUP_FILE" ]]; then
    echo "Usage: $0 <database-backup.sql.gz> [uploads-backup.tgz]" >&2
    exit 1
fi

cd /opt/learning

if [[ -f "$BACKUP_FILE.sha256" ]]; then
    echo "Verifying checksum ..."
    sha256sum -c "$BACKUP_FILE.sha256"
else
    echo "WARNING: no .sha256 file found for this backup." >&2
fi

read -r -p "This will OVERWRITE the current database. Continue? [y/N] " answer
if [[ ! "$answer" =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo "Stopping the API ..."
docker compose stop api

echo "Restoring database from $BACKUP_FILE ..."
gzip -dc "$BACKUP_FILE" \
  | docker compose exec -T db sh -c \
      'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

if [[ -n "$UPLOADS_FILE" && -f "$UPLOADS_FILE" ]]; then
    read -r -p "Restore uploads from $UPLOADS_FILE? [y/N] " upload_answer
    if [[ "$upload_answer" =~ ^[Yy]$ ]]; then
        echo "Restoring uploads ..."
        tar xzf "$UPLOADS_FILE" -C /opt/learning
        sudo chown -R www-data:www-data /opt/learning/lms-api/uploads || true
    fi
fi

echo "Starting the API ..."
docker compose start api
docker compose ps
echo "Restore finished. Verify login and a read/write operation with a real account."
