# Operations Runbook

Day-2 procedures for a running LMS deployment: backups, migrations, scheduled jobs, monitoring,
upgrades, and rollback.

Commands assume the Docker Compose stack from [`deploy-docker.md`](deploy-docker.md). Adapt paths
for bare-metal installs.

## Golden rules

1. **Back up before any change that touches data.** A backup you have not restored is not a
   backup — test restores periodically.
2. **Run migrations one at a time and read the output.**
3. **Dry-run data migrations first.**
4. **Never run `docker compose down -v` on a system you care about.**
5. **Record what you changed and when** (migrations, image tags, secret rotations).

## 1. Backups

### What to back up

| Asset | Location | Why |
| --- | --- | --- |
| Database | `db_data` volume (or your managed DB) | Users, content, grades, audit logs |
| Uploads | `lms-api/uploads/` | Profile photos, materials, submissions, attachments |
| Configuration | `.env.docker`, `lms-api/.env.docker` | Secrets and environment settings |
| Application version | Git commit / image tag | Reproducible redeploy |

### Database dump

```bash
mkdir -p backups
docker compose exec -T db sh -c \
  'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  > "backups/lms-$(date +%F-%H%M).sql"
```

Verify the dump is non-empty and ends with a `-- Dump completed` line:

```bash
ls -lh backups/
tail -n 3 "backups/lms-$(date +%F-%H%M).sql"
```

### Uploads

```bash
tar czf "backups/uploads-$(date +%F-%H%M).tgz" lms-api/uploads
```

For large upload directories, use a filesystem snapshot or object-storage sync instead.

### Configuration

Store the `.env` files in your secret manager or an encrypted backup. Never commit them.

## 2. Restore

Stop the API first so nothing writes during the restore:

```bash
docker compose stop api

docker compose exec -T db sh -c \
  'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  < backups/lms-2026-11-17-1200.sql

tar xzf backups/uploads-2026-11-17-1200.tgz

docker compose start api
```

After restoring, verify login and a read/write operation with a non-admin account.

## 3. Applying schema migrations

Migrations live in `lms-api/migrations/` and are standalone scripts. They are not run
automatically and have no central runner.

### Procedure

1. Read the migration script. Confirm what it changes and whether it is idempotent.
2. Back up the database.
3. Run it once:

```bash
docker compose exec api php migrations/<migration_file>.php
```

4. Verify the result:

```bash
docker compose exec db sh -c \
  'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SHOW COLUMNS FROM <table>;"'
```

5. Record the migration name, date, and environment in your change log.

### Ordering

Some migrations depend on earlier ones (for example, tables must exist before backfills). Apply
them in the order they were written. When in doubt, apply on staging first.

## 4. Legacy upload migration

This rewrites database references and copies files from the flat layout to the
institution-scoped layout.

### Dry run (always first)

```bash
docker compose exec api php scripts/migrate_uploads_to_institutions.php \
  --db-host=db --db-user=lms_user --db-pass=<app-password>
```

Review:

- files to copy
- database references to update
- skipped items (missing sources, ambiguous ownership)
- collisions

### Apply

1. Back up the database **and** `lms-api/uploads/`.
2. Run with `--apply`:

```bash
docker compose exec api php scripts/migrate_uploads_to_institutions.php \
  --db-host=db --db-user=lms_user --db-pass=<app-password> --apply
```

3. Re-run the dry run. It should report **0** files to copy and **0** references to update:

```bash
docker compose exec api php scripts/migrate_uploads_to_institutions.php \
  --db-host=db --db-user=lms_user --db-pass=<app-password>
```

4. Spot-check references in the database and open a few files in the browser.

Files are **copied, never moved**, so a failed run can be repeated safely. Keep the original
files until you have verified the new paths.

### Known leftovers

Some databases contain references whose source files are missing, or global announcement
attachments with no clear institution. These are reported and left unchanged. Resolve them
manually with the content owner; do not guess.

## 5. Scheduled reports

Run the worker every 15 minutes (host cron):

```bash
*/15 * * * * cd /srv/learning && docker compose exec -T api php scripts/report_schedule_worker.php >/dev/null 2>&1
```

On a bare-metal install, use the bundled installer:

```bash
./lms-api/scripts/install_scheduler_unix.sh 15
```

Windows: `lms-api/scripts/install_scheduler_windows.ps1`.

Useful helpers:

| Script | Purpose |
| --- | --- |
| `scripts/report_schedule_worker.php` | Process due schedules once |
| `scripts/report_schedule_loop.php` | Long-running loop |
| `scripts/verify_schedules.php` | Inspect schedules and due times |
| `scripts/set_schedules_due_now.php` | Test helper |

Alternatively set `REPORT_SCHEDULER_AUTO_TRIGGER=true` and tune
`REPORT_SCHEDULER_TRIGGER_COOLDOWN_SECONDS`. Explicit scheduling is more predictable.

## 6. Monitoring and health checks

There is no dedicated health endpoint. Use these checks:

```bash
# API process responds (401 without a token means it is up)
curl -s -o /dev/null -w '%{http_code}\n' https://api.example.com/api/auth/me

# Containers are running
docker compose ps

# Database accepts connections
docker compose exec -T db sh -c \
  'exec mysqladmin -u root -p"$MYSQL_ROOT_PASSWORD" ping'

# Disk space for uploads and the database volume
df -h
docker system df
```

Alert on:

- API `5xx` rate and latency
- database connection failures
- disk usage above 80%
- repeated `401`/`403` spikes (credential stuffing or misconfiguration)
- backup job failures
- report scheduler backlog

Application errors are written under `lms-api/logs/`. Review them during incidents.

## 7. Log rotation

`lms-api/logs/` and the proxy logs grow over time. Example `logrotate` policy:

```conf
/srv/learning/lms-api/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

Test with `logrotate -d /etc/logrotate.d/lms` before relying on it.

## 8. Release / upgrade procedure

1. **Read the changes.** Note migrations, environment variable changes, and breaking changes.
2. **Run the test harness** against a staging database:

```bash
php lms-api/tests/run.php --db-host=<host> --db-user=<user> --db-pass=<pass>
```

3. **Back up** the database and uploads.
4. **Build** the new images:

```bash
docker compose --env-file .env.docker build
```

5. **Apply migrations** one at a time (section 3).
6. **Restart** services:

```bash
docker compose --env-file .env.docker up -d
```

7. **Verify** with section 6 plus a login and a read/write smoke test.
8. **Record** the release commit and image tags.

## 9. Rollback

### Application only (no schema change)

```bash
git checkout <previous-commit>
docker compose --env-file .env.docker up -d --build
```

### With schema or data changes

1. Stop the API.
2. Restore the pre-release database dump (section 2).
3. Restore the previous application version.
4. Start the API and verify.

Keep the previous image tag or commit available before starting a release.

## 10. Routine maintenance checklist

**Daily**

- [ ] Backups completed and non-empty
- [ ] API and database healthy
- [ ] Disk usage within limits

**Weekly**

- [ ] Review error logs
- [ ] Review report scheduler runs
- [ ] Confirm uploads are being written and served

**Monthly**

- [ ] Test a restore into a scratch database
- [ ] Review user accounts and roles (remove stale admins)
- [ ] Apply OS and dependency security updates
- [ ] Confirm no `.env` files or secrets are tracked in Git

**After any secret exposure**

- [ ] Rotate the affected secret
- [ ] Invalidate sessions if `JWT_SECRET` changed
- [ ] Follow [`../security/phase7-secret-rotation.md`](../security/phase7-secret-rotation.md)

## 11. Verification commands

```bash
# Static checks
php -l lms-api/public/index.php
php -l lms-api/src/Controllers/AuthController.php

# Full API harness
php lms-api/tests/run.php --db-host=localhost --db-user=root --db-pass=

# Frontend environment generator
node lms-frontend/scripts/generate-env.js

# Git hygiene
git status
git diff --check
```
