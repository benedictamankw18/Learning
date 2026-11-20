#!/usr/bin/env bash
# Quick health report for the Learning LMS stack.
cd /opt/learning

echo "=== Containers ==="
docker compose ps

echo
echo "=== API status (401 is expected without a token) ==="
printf 'direct API : '
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/api/auth/me || true
printf 'via proxy  : '
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/api/auth/me || true
printf 'frontend   : '
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/ || true

echo
echo "=== Resource usage ==="
docker stats --no-stream

echo
echo "=== Disk space ==="
df -h / /mnt/learning-backups 2>/dev/null || df -h

echo
echo "=== Most recent backups ==="
ls -lt /mnt/learning-backups/ 2>/dev/null | head -n 10 || echo "Backup share not mounted"

echo
echo "=== Recent backup log ==="
tail -n 10 /var/log/learning-backup.log 2>/dev/null || true
