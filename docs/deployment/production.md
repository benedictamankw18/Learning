# Production Deployment

This guide covers deploying the LMS to a server that real users access over the internet. It
assumes you have already read [`deploy-docker.md`](deploy-docker.md) and understand the
components.

Production differs from development in four ways that matter:

1. Everything is served over HTTPS.
2. Secrets are real, unique, and never committed.
3. CORS is an explicit allowlist, not `*`.
4. There are backups, monitoring, and a rollback path.

## Recommended architecture

```text
                    ┌──────────────────────────┐
   users ── HTTPS ──►  reverse proxy (TLS)      │
                    │  nginx / Caddy / ALB      │
                    └───────┬──────────┬────────┘
                            │          │
                 /           │          │  /api, /uploads
                            ▼          ▼
                    ┌────────────┐  ┌────────────┐
                    │ frontend   │  │ API        │
                    │ nginx:80   │  │ Apache/PHP │
                    └────────────┘  └─────┬──────┘
                                          │
                                    ┌─────▼──────┐
                                    │ MariaDB    │
                                    └────────────┘
```

Terminate TLS at the proxy. Keep the API and database on a private network; expose only the
proxy's `443` (and `80` for redirects).

## Pre-flight checklist

- [ ] Server with Docker Engine + Compose v2 (or PHP 8.2 + Apache + MariaDB for bare metal)
- [ ] DNS records for the frontend and API hostnames
- [ ] TLS certificate (Let's Encrypt or managed)
- [ ] Strong, unique database passwords
- [ ] Strong `JWT_SECRET` (≥32 random characters)
- [ ] Real SMTP credentials (not MailHog)
- [ ] Backup destination for database and uploads
- [ ] Decision on same-origin vs separate API hostname (affects CORS and `API_BASE_URL`)

## 1. Secrets

Never reuse the example values. Generate:

```bash
# JWT signing key
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# Database passwords
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Store them in the deployment's secret mechanism (Docker secrets, your platform's secret store,
or root-owned `.env` files with restrictive permissions). Do not commit them.

If any secret was ever committed to Git, treat it as compromised and rotate it. Follow
[`../security/phase7-secret-rotation.md`](../security/phase7-secret-rotation.md).

## 2. Environment configuration

Create `.env.docker` and `lms-api/.env.docker` from the templates, then set production values.

### Required production values

| Variable | Production value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://api.example.com` |
| `FRONTEND_URL` | `https://lms.example.com` |
| `ALLOWED_ORIGINS` | `https://lms.example.com` (comma-separated; never `*`) |
| `JWT_SECRET` | ≥32 random characters, unique to this environment |
| `DB_HOST` | `db` in Compose, or your database host |
| `DB_USER` | A dedicated application user, not `root` |
| `DB_PASS` | Strong random password |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Real SMTP provider |
| `MAIL_FROM_ADDRESS` | A monitored sender address |
| `REPORT_SCHEDULER_AUTO_TRIGGER` | `false` if you run a dedicated scheduler |
| `MAX_UPLOAD_SIZE` | Reserved; set PHP `upload_max_filesize` and `post_max_size` to match your proxy limits |

### Frontend API URL

`API_BASE_URL` is the **API origin only** — do not append `/api`. The frontend adds `/api/...`
to every endpoint itself.

| Deployment style | `API_BASE_URL` |
| --- | --- |
| Separate API hostname | `https://api.example.com` |
| Same origin (proxy routes `/api`) | `https://lms.example.com` |
| LAN / self-hosted (API on same host, port 8081) | `auto` |

`auto` derives the API origin from the page's own host, so one build works from `localhost` or any
LAN IP. Use an explicit URL for a public deployment.

It is a Docker **build argument**. Set it before building the frontend image:

```bash
API_BASE_URL=https://api.example.com docker compose --env-file .env.docker build frontend
```

Changing it later requires rebuilding the frontend image.

## 3. Build and start

```bash
# Build with production values
docker compose --env-file .env.docker build

# Start detached
docker compose --env-file .env.docker up -d

# Confirm health
docker compose ps
docker compose logs --tail=100 api
```

If the database volume is new, MariaDB runs `db/init/*.sql` automatically. If you are restoring
an existing database, restore the dump instead of relying on the init scripts.

## 4. Provision the super admin

Do not use the seeded demo account. Create a real one:

```bash
docker compose exec api php scripts/create_superadmin.php --email=admin@example.com
```

Record the generated password in your password manager, then sign in and change it if your
policy requires. Add `--force` to rotate an existing account.

## 5. Reverse proxy and TLS

The following nginx example assumes the frontend and API are separate hostnames. Adjust paths
and certificate locations.

```nginx
# Frontend
server {
    listen 443 ssl http2;
    server_name lms.example.com;

    ssl_certificate     /etc/letsencrypt/live/lms.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/lms.example.com/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Some pages emit relative /uploads/... URLs, so route them to the API.
    location /uploads/ {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location / {
        proxy_pass http://127.0.0.1:8082;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# API
server {
    listen 443 ssl http2;
    server_name api.example.com;

    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;

    client_max_body_size 100m;   # must be >= PHP upload_max_filesize/post_max_size

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Authorization $http_authorization;
    }
}
```

Redirect HTTP to HTTPS on both hostnames.

> If you use a **same-origin** deployment instead, route `/api/` and `/uploads/` to the API and
> everything else to the frontend. CORS is then unnecessary, but keep `ALLOWED_ORIGINS` set to
> the site origin anyway.

## 6. Hardening

### Application

- `APP_ENV=production`, `APP_DEBUG=false`.
- Explicit `ALLOWED_ORIGINS`; never `*` with credentialed requests.
- Unique `JWT_SECRET` per environment; rotating it invalidates all sessions.
- Keep the seeded demo accounts out of production; provision real users.
- Review `db/init/02-insert.sql` before importing; it creates known accounts and demo records.

### Database

- Use a dedicated application user with privileges limited to the LMS database.
- Do not expose port `3306`/`3307` publicly.
- Enable regular backups and test restores.
- Do not run `docker compose down -v` in production.

### Uploads

- Keep `lms-api/uploads/` on a persistent volume.
- The API caps individual uploads at 100 MB and the image sets `upload_max_filesize=100M` /
  `post_max_size=105M`. Keep the reverse proxy `client_max_body_size` at or above that.
- The bundled Apache config disables directory listings, denies script execution, and sends
  `nosniff`. Do not weaken it.
- **Known limitation:** files under `/uploads/...` are readable without authentication by
  anyone who knows the URL. Closing this requires serving files through the authenticated
  `GET /api/upload/...` endpoints and migrating stored URLs. Until then, treat upload URLs as
  unlisted rather than private.

### Network and host

- Expose only `443` (and `80` for redirects) and your SSH port.
- Keep the API, frontend, and database on a private network.
- Apply OS security updates and run a host firewall.
- Use a non-root deploy user; protect `.env` files with restrictive permissions.

### Logging and monitoring

- Ship or rotate `lms-api/logs/` and the proxy logs.
- Alert on API `5xx` rates, database connectivity, disk usage, and failed logins.
- Back up before every migration.

## 7. Scheduled reports

Run the worker on a schedule you control:

```bash
# Host cron (every 15 minutes), using the container
*/15 * * * * cd /srv/learning && docker compose exec -T api php scripts/report_schedule_worker.php >/dev/null 2>&1
```

Alternatively set `REPORT_SCHEDULER_AUTO_TRIGGER=true` so HTTP requests occasionally trigger the
worker. For dependable delivery, prefer an explicit scheduler.

## 8. Go-live verification

```bash
# HTTPS and redirects
curl -I https://lms.example.com
curl -I http://lms.example.com        # should redirect to HTTPS

# API reachable (401 without a token)
curl -i https://api.example.com/api/auth/me

# Login works
curl -X POST https://api.example.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"<username>","password":"<password>"}'

# CORS allows the frontend origin
curl -i -X OPTIONS https://api.example.com/api/auth/login \
  -H "Origin: https://lms.example.com" \
  -H "Access-Control-Request-Method: POST"

# Uploads are served with nosniff
curl -I https://api.example.com/uploads/<institution_id>/<category>/<filename>

# Database backup is restorable
# (run a restore into a scratch database, not production)
```

Then walk the manual checklist in the root `README.md` with a real account for each role.

## 9. Rollback

Keep the previous image tag and a database dump. See [`operations.md`](operations.md) for the
full procedure. In short:

```bash
docker compose down
# restore the previous images/tag and .env values
docker compose up -d
# restore the database dump if the release changed data
```

## 10. Known gaps to plan around

- No CI pipeline; run `php lms-api/tests/run.php` before every release.
- Database changes use individual migration scripts, not a tracked runner. Record which
  migrations were applied to each environment.
- Static uploads are not access-controlled (see the uploads limitation above).
- The frontend is a static bundle; `API_BASE_URL` changes require a rebuild.

## See also

- [`deploy-docker.md`](deploy-docker.md) — stack details and commands
- [`operations.md`](operations.md) — backups, migrations, monitoring, rollback
- [`../security/phase7-secret-rotation.md`](../security/phase7-secret-rotation.md) — secret rotation and history purge
