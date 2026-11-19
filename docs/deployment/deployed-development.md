# Local Development Deployment

Use this guide when you are developing or testing the LMS on a workstation. It covers
running the API and frontend directly (no containers), including an XAMPP/Apache option.

For the containerized stack, see [`deploy-docker.md`](deploy-docker.md).

## Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | 8.2 recommended (8.0+ minimum) | Extensions: `pdo`, `pdo_mysql`, `mbstring`, `json` |
| Composer | any recent 2.x | A bundled `lms-api/composer.phar` may also exist |
| MariaDB | 10.4+ | MySQL 8 works too |
| Node.js | 18+ | Frontend env generation and Tailwind |
| Static file server | any | Python, Node, or PHP built-in server |

## 1. Get the code

```bash
git clone <repository-url> Learning
cd Learning
```

Confirm the three main directories exist: `lms-api/`, `lms-frontend/`, `db/`.

## 2. Create and seed the database

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p lms < db/init/01-lms.sql
mysql -u root -p lms < db/init/02-insert.sql
mysql -u root -p lms < db/init/03-refresh-tokens.sql
mysql -u root -p lms < db/init/04-quiz-submission-questions.sql
```

| File | Contents |
| --- | --- |
| `01-lms.sql` | Schema (all tables, keys, indexes) |
| `02-insert.sql` | Roles, permissions, platform super admin, demo institution data |
| `03-refresh-tokens.sql` | Refresh-token storage used by login/refresh/rotation |
| `04-quiz-submission-questions.sql` | Per-attempt question order for "Shuffle Questions" |

Applying `01-lms.sql` to a database that already holds data will not add the newer columns.
Run the standalone migrations instead, for example:

```bash
php lms-api/migrations/add_prevent_backtracking_to_quizzes.php
```

Each migration checks whether its change already exists and skips safely.

> **The seeded super admin must not be used outside local development.** Its password hash is
> committed to the repository, so the password is public knowledge. Provision a real account:

```bash
php lms-api/scripts/create_superadmin.php --email=you@example.com
```

The script prints the generated password once. Add `--username=...` to choose the username and
`--force` to rotate an existing account. Add `--password=...` only in automation.

## 3. Configure the API

```bash
cp lms-api/.env.example lms-api/.env
```

Edit `lms-api/.env`:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lms
DB_USER=root
DB_PASS=

JWT_SECRET=<generate-a-random-secret-with-the-command-below>
JWT_ISSUER=lms-api
JWT_AUDIENCE=lms-client
JWT_ACCESS_EXPIRY=3600
JWT_REFRESH_EXPIRY=604800

APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
APP_TIMEZONE=UTC

ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000

MAIL_HOST=localhost
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="LMS System"

# Uploads always go to lms-api/uploads; the STORAGE_* and MAX_UPLOAD_SIZE
# variables are reserved. The effective limit is PHP upload_max_filesize
# and post_max_size (see your php.ini).
STORAGE_TYPE=local
STORAGE_PATH=../uploads
MAX_UPLOAD_SIZE=104857600

RATE_LIMIT_REQUESTS=100
RATE_LIMIT_WINDOW=60
DEFAULT_PAGE_SIZE=20
MAX_PAGE_SIZE=100

REPORT_SCHEDULER_AUTO_TRIGGER=true
REPORT_SCHEDULER_TRIGGER_COOLDOWN_SECONDS=120
```

Generate a JWT secret:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

`JWT_SECRET` is validated every time it is loaded. A missing secret, a value shorter than 32
characters, or a known placeholder causes the API to fail closed with a configuration error.
This is intentional — see [`production.md`](production.md) for the production requirements.

## 4. Install API dependencies

```bash
cd lms-api
composer install
cd ..
```

If `composer` is not on your `PATH`, use the bundled phar: `php lms-api/composer.phar install`.

## 5. Start the API

### Option A — PHP built-in server (recommended for quick work)

From the repository root:

```bash
php -S localhost:8000 -t lms-api/public lms-api/public/router.php
```

The router handles:

- `/api/*` and other routes through `public/index.php`
- `/uploads/*` and legacy `/materials/*` file serving (with path containment and `nosniff`)
- CORS preflight (`OPTIONS`)

The API is available at `http://localhost:8000/api`.

### Option B — XAMPP / Apache

1. Enable `mod_rewrite`.
2. Point a virtual host's `DocumentRoot` at `lms-api/public` (not at the repository root and
   not at `lms-api/`).
3. Allow `.htaccess` overrides for that directory (`AllowOverride All`).
4. Ensure the `uploads/` and `logs/` directories are writable by the web server user.
5. Set `APP_URL` to the virtual host URL and add the frontend origin to `ALLOWED_ORIGINS`.

`public/.htaccess` routes non-file requests to `index.php` and forwards the `Authorization`
header. `public/router.php` is only used by the PHP built-in server.

> Do not point the document root at the repository root: that exposes `.env`, source code, and
> the database init scripts.

## 6. Configure and build the frontend

```bash
cd lms-frontend
cp .env.example .env
```

Set at least:

```dotenv
APP_ENV=development
API_BASE_URL=http://localhost:8000
```

Then generate the runtime config and build Tailwind:

```bash
node scripts/generate-env.js
npm install
npm run build
```

`scripts/generate-env.js` writes `assets/js/env.js` (`window.ENV_CONFIG`). It is loaded before
`assets/js/config.js`, and must be regenerated whenever `.env` changes.

Available scripts (from `lms-frontend/`):

| Command | Purpose |
| --- | --- |
| `npm run build` | Minified Tailwind build |
| `npm run dev` | Tailwind watch mode |
| `npm run vendor:copy` | Copy `xlsx` / `jspdf` bundles into `assets/vendor/` (Windows PowerShell script) |

## 7. Serve the frontend

```bash
# From lms-frontend/
python -m http.server 3000
# or: npx http-server -p 3000
# or: php -S localhost:3000
```

Open `http://localhost:3000`. The landing page links to `auth/login.html`.

`DEMO_CREDENTIALS` in `lms-frontend/.env` powers the quick-login buttons. Never set it outside
local development.

## 8. Verify the installation

```bash
# API is reachable (401 is expected without a token)
curl -i http://localhost:8000/api/auth/me

# Log in with a seeded or provisioned account
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"<username-or-email>","password":"<password>"}'
```

Run the API test harness (requires the database from step 2, including `refresh_tokens`):

```bash
php lms-api/tests/run.php --db-host=localhost --db-user=root --db-pass=
```

A passing run ends with `failed: 0`. Exit code `0` means every test passed or skipped.

## 9. Optional — migrate legacy uploads

If the database contains old flat upload references (`uploads/<file>`), run the migration in
dry-run mode first:

```bash
php lms-api/scripts/migrate_uploads_to_institutions.php --db-host=localhost --db-user=root --db-pass=
```

Review the report, back up the database and `lms-api/uploads/`, then apply:

```bash
php lms-api/scripts/migrate_uploads_to_institutions.php --db-host=localhost --db-user=root --db-pass= --apply
```

The script copies files (it does not move them) and rewrites database references to
`uploads/<institution_id>/<category>/<filename>`. See [`operations.md`](operations.md) for the
full procedure.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `JWT_SECRET is required` / `must be at least 32 characters` | The API fails closed. Set a real `JWT_SECRET` in `lms-api/.env`. |
| `401 Unauthorized` on every request | Token missing/expired, or `JWT_SECRET` changed (invalidates all sessions). Log in again. |
| Browser CORS error | Add the frontend origin to `ALLOWED_ORIGINS` and restart the API. |
| Frontend calls the wrong API URL | `assets/js/env.js` is stale. Update `.env` and re-run `node scripts/generate-env.js`. |
| `env.js` missing / `undefined` config | Run the generator and confirm `env.js` loads before `config.js`. |
| `Endpoint not found` | Check the method and path in `lms-api/src/Routes/api.php`; routing is method-sensitive. |
| Uploads return 404 | Ensure `lms-api/uploads/` exists and is readable. The dev router serves `/uploads/*`. |
| `Class not found` / autoload errors | Run `composer install` in `lms-api/` and confirm the `App\` PSR-4 mapping. |
| Apache serves source or `.env` | Document root is wrong. Point it at `lms-api/public`. |
| Test harness cannot connect | Pass `--db-host`, `--db-user`, `--db-pass` explicitly; the defaults may not match your local server. |

## Development security reminders

- Keep `.env` files out of Git; only `.example` templates are tracked.
- Use a unique `JWT_SECRET` per environment.
- Do not reuse the seeded demo accounts for anything shared.
- Test destructive operations against a local database, never against shared data.
