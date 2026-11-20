# Docker Compose Deployment

This guide runs the complete stack with Docker Compose: MariaDB, the PHP/Apache API, the Nginx
frontend, and MailHog for local email capture. It is the fastest way to get a working system and
the recommended base for staging.

For a single-workstation setup without Docker, see [`deployed-development.md`](deployed-development.md).
For internet-facing production, read [`production.md`](production.md) as well.

## Prerequisites

- Docker Desktop, or Docker Engine with the Compose v2 plugin
- Ports `8081`, `8082`, `8025`, `1025`, and `3307` free on the host

Verify:

```bash
docker --version
docker compose version
```

## 1. Create the environment files

```bash
cp .env.docker.example .env.docker
cp lms-api/.env.docker.example lms-api/.env.docker
```

### `.env.docker` (root)

Used by the `db` service and by Compose `${...}` interpolation.

| Variable | Purpose | Example |
| --- | --- | --- |
| `DB_ROOT_PASSWORD` | MariaDB root password | `<strong-root-password>` |
| `DB_NAME` | Database name | `lms` |
| `DB_USER` | Application database user | `lms_user` |
| `DB_PASS` | Application database password | `<strong-app-password>` |
| `API_BASE_URL` | API URL the browser will call; `auto` derives it from the page host | `auto` |

### `lms-api/.env.docker` (API container)

| Variable | Purpose |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Must point at the `db` service (`DB_HOST=db`, `DB_PORT=3306`) and match `.env.docker` |
| `JWT_SECRET` | Signing key, **at least 32 characters**, not a placeholder |
| `JWT_ISSUER`, `JWT_AUDIENCE` | Token claims |
| `JWT_ACCESS_EXPIRY`, `JWT_REFRESH_EXPIRY` | Token lifetimes in seconds |
| `APP_ENV`, `APP_DEBUG` | `production` / `false` for anything shared |
| `APP_URL`, `FRONTEND_URL` | Public URLs used in links and emails |
| `ALLOWED_ORIGINS` | Comma-separated CORS allowlist — never `*` in production |
| `MAIL_*` | SMTP settings; the template points at `mailhog:1025` |
| `STORAGE_TYPE`, `STORAGE_PATH` | Reserved; uploads always go to `lms-api/uploads`, mounted at `/var/www/html/uploads` |
| `MAX_UPLOAD_SIZE` | Reserved; the effective limit is PHP `upload_max_filesize` / `post_max_size` (100 MB / 105 MB in the API image) |
| `RATE_LIMIT_REQUESTS`, `RATE_LIMIT_WINDOW` | Rate limiting |
| `DEFAULT_PAGE_SIZE`, `MAX_PAGE_SIZE` | Pagination limits |
| `REPORT_SCHEDULER_AUTO_TRIGGER`, `REPORT_SCHEDULER_TRIGGER_COOLDOWN_SECONDS` | Opportunistic report worker |

Generate a JWT secret:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The API validates `JWT_SECRET` on load and refuses to start with a missing, short, or placeholder
value.

### Frontend API URL

The frontend image receives `API_BASE_URL` as a **build argument**. The default, `auto`, derives the
API origin from the page's own host: a page served from `http://192.168.1.20:8082` calls
`http://192.168.1.20:8081`. That means one build works from `localhost` or any LAN IP, even when the
server's address changes.

Set an explicit URL (for example `https://api.example.com`) when the API is on a different host, or
provide a comma-separated list to try multiple hosts in order. Compose passes
`${API_BASE_URL:-auto}` from `.env.docker`.

## 2. Build and start

```bash
docker compose --env-file .env.docker up --build
```

`--env-file` makes Compose interpolate `${...}` from `.env.docker`, matching the file the `db`
service receives through `env_file`.

Run detached:

```bash
docker compose --env-file .env.docker up --build -d
```

## 3. Verify

| Service | URL | Notes |
| --- | --- | --- |
| Frontend | http://localhost:8082 | Nginx static site |
| API | http://localhost:8081/api | Apache + PHP |
| MailHog UI | http://localhost:8025 | Captured outbound email |
| MailHog SMTP | `localhost:1025` | Configure `MAIL_HOST`/`MAIL_PORT` to use it |
| MariaDB | `localhost:3307` | Host port maps to container port `3306` |

```bash
docker compose ps
curl -i http://localhost:8081/api/auth/me   # 401 is expected without a token
docker compose logs -f api
```

### Accessing from another computer (LAN)

Both published ports bind to `0.0.0.0`, so other machines on the same network can use
`http://<server-ip>:8082`. With `API_BASE_URL=auto`, the frontend automatically calls the API on the
same host at port `8081`.

1. Find the server's IPv4 address (for example `192.168.10.96`):

   ```powershell
   Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' }
   ```

2. Allow inbound TCP `8081` and `8082` in Windows Defender Firewall (run PowerShell as
   Administrator):

   ```powershell
   New-NetFirewallRule -DisplayName "LMS frontend 8082" -Direction Inbound -Protocol TCP -LocalPort 8082 -Action Allow
   New-NetFirewallRule -DisplayName "LMS API 8081" -Direction Inbound -Protocol TCP -LocalPort 8081 -Action Allow
   ```

3. Open `http://<server-ip>:8082` on the other machine. Use the full page name, for example
   `http://<server-ip>:8082/auth/login.html` — directory URLs such as `/auth/` are not pages.

CORS reflects private/LAN origins automatically (`localhost`, `127.0.0.1`, `10.x`, `192.168.x`,
`172.16-31.x`). Public domains still must be listed in `ALLOWED_ORIGINS`.

### No internet required

Every third-party front-end library is vendored under `lms-frontend/assets/vendor/` and served by
nginx: Font Awesome, Chart.js, jsPDF, jsPDF-AutoTable, JSZip, SheetJS, Tailwind Play, and Bootstrap.
Application pages reference only local assets (no `http`/`https` asset URLs) and make no CDN
requests, so the LMS keeps working on an isolated LAN. Versions, licenses, and source URLs are
listed in `lms-frontend/assets/vendor/README.md`.

## 4. Services and volumes

| Service | Image / build | Ports | Persistent data |
| --- | --- | --- | --- |
| `db` | `mariadb:10.11` | `3307:3306` | Named volume `db_data` |
| `api` | `./lms-api` | `8081:80` | `./lms-api/uploads`, `./lms-api/logs` |
| `frontend` | `./lms-frontend` | `8082:80` | none |
| `mailhog` | `mailhog/mailhog` | `8025`, `1025` | none |

The API image:

- sets the Apache document root to `/var/www/html/public`
- serves `/uploads` from `/var/www/html/uploads` through an `Alias`
- disables directory listings and CGI, denies script extensions under `uploads/`, and sends
  `X-Content-Type-Options: nosniff`
- raises PHP upload limits to `upload_max_filesize=100M`, `post_max_size=105M`,
  `memory_limit=256M`
- excludes `uploads/` from the build context, so user files are never baked into the image

## 5. Database initialization

On first start, MariaDB executes every file in `db/init/` against an empty data directory, in
filename order:

| File | Contents |
| --- | --- |
| `01-lms.sql` | Schema |
| `02-insert.sql` | Roles, permissions, super admin, demo data |
| `03-refresh-tokens.sql` | Refresh-token storage |
| `04-quiz-submission-questions.sql` | Per-attempt question order for "Shuffle Questions" |
| `05-question-bank.sql` | Teacher-private question bank tables and metadata columns |
| `06-question-bank-metadata.sql` | Question taxonomy and immutable version tables |

Initialization **only runs on an empty volume**. If `db_data` already exists, the scripts are
skipped. Apply new init files to an existing volume manually:

```bash
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  < db/init/04-quiz-submission-questions.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  < db/init/05-question-bank.sql
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  < db/init/06-question-bank-metadata.sql
```

The question-bank init files create the tables but not the owner-scoped taxonomy keys.
Run `scope_question_bank_to_teachers.php` (section 6) after importing them, even on a
fresh volume. See [`../assessment/question-bank.md`](../assessment/question-bank.md).

> Provision a real super admin instead of using the seeded demo account:

```bash
docker compose exec api php scripts/create_superadmin.php --email=you@example.com
```

The script prints the generated password once. Add `--force` to rotate an existing account.

## 6. Migrations

Migrations are standalone scripts and are **not** run automatically. Run them individually
inside the API container:

```bash
docker compose exec api php migrations/create_refresh_tokens_table.php
docker compose exec api php migrations/add_institution_id_to_announcements.php
docker compose exec api php migrations/add_prevent_backtracking_to_quizzes.php
docker compose exec api php migrations/add_quiz_submission_progress.php
```

For the teacher-private question bank, apply the sequence in this order (the last step is a
one-time ownership normalization):

```bash
docker compose exec api php migrations/create_question_bank.php
docker compose exec api php migrations/add_question_snapshot_to_quiz_submission_questions.php
docker compose exec api php migrations/add_question_bank_metadata.php
docker compose exec api php migrations/scope_question_bank_to_teachers.php
```

`scope_question_bank_to_teachers.php` assigns legacy ownerless questions to user 11
(`benedict.teacher`) when such rows exist, marks legacy rows private/draft, and adds the
owner-scoped taxonomy keys. Already-owned rows keep their owner; no API changes ownership later.
If there is nothing to reassign, user 11 does not have to exist. It is safe to run after a fresh
init as well as an upgrade. See
[`../assessment/question-bank.md`](../assessment/question-bank.md).

Most migrations check whether a column or table already exists and skip safely. Back up the
database first when running against data you care about — see [`operations.md`](operations.md).

## 7. Uploads

Uploads are written to `lms-api/uploads/` on the host and served at `/uploads/...`.
Individual files are capped at 100 MB by the API; PHP is configured for the same limit in the
API image (`upload_max_filesize=100M`, `post_max_size=105M`).

- New uploads are stored as `uploads/<institution_id>/<category>/<filename>`.
- Legacy flat uploads can be migrated in place:

```bash
# Dry run first
docker compose exec api php scripts/migrate_uploads_to_institutions.php \
  --db-host=db --db-user=lms_user --db-pass=<app-password>

# Apply after reviewing the report and backing up
docker compose exec api php scripts/migrate_uploads_to_institutions.php \
  --db-host=db --db-user=lms_user --db-pass=<app-password> --apply
```

Back up `lms-api/uploads/` before applying.

## 8. Scheduled reports

The worker is not scheduled by Compose. Options:

```bash
# Run once, manually
docker compose exec api php scripts/report_schedule_worker.php

# Long-running loop
docker compose exec api php scripts/report_schedule_loop.php
```

Or rely on opportunistic triggering: set `REPORT_SCHEDULER_AUTO_TRIGGER=true` and tune
`REPORT_SCHEDULER_TRIGGER_COOLDOWN_SECONDS`. For reliable delivery, add a host cron entry or a
dedicated scheduler container; see [`operations.md`](operations.md).

## 9. Common commands

```bash
# Status and logs
docker compose ps
docker compose logs -f api
docker compose logs -f db

# Shell into a container
docker compose exec api bash
docker compose exec db mysql -u root -p

# Restart after changing lms-api/.env.docker
docker compose up -d --force-recreate api

# Rebuild after code or Dockerfile changes
docker compose --env-file .env.docker up --build -d

# Stop, keeping data
docker compose down

# Stop and DELETE the database volume (destructive)
docker compose down -v
```

> `docker compose down -v` permanently deletes `db_data`. Uploads and logs live on host bind
> mounts and are not removed by it.

## 10. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `API_BASE_URL build arg is required` | Set `API_BASE_URL` in `.env.docker` and rebuild the frontend. |
| Frontend loads but API calls fail | `API_BASE_URL` was baked in at build time. Change it, then rebuild the frontend image. |
| `JWT_SECRET must be at least 32 characters` | Set a real secret in `lms-api/.env.docker` and recreate the API container. |
| `401` on every request | Token expired, or `JWT_SECRET` changed. Log in again. |
| CORS error | Add the frontend origin to `ALLOWED_ORIGINS` and recreate the API container. |
| Init scripts did not run | They run only on an empty volume. Reset with `down -v` (destroys data) or import manually. |
| Cannot connect to the database | Inside Compose use `DB_HOST=db`; from the host use `localhost:3307`. |
| Emails not delivered | Use `MAIL_HOST=mailhog`, `MAIL_PORT=1025`; inspect http://localhost:8025. |
| Uploads 404 | Confirm the `./lms-api/uploads` bind mount exists and the container can read it. |
| Permission errors on uploads/logs | Fix host ownership so the container user can write to the bind mounts. |

## 11. Before production

The Compose file is a development/staging baseline. For internet-facing deployment you still
need TLS termination, explicit CORS origins, real SMTP, strong secrets, database least
privilege, backups, and monitoring. Follow [`production.md`](production.md).
