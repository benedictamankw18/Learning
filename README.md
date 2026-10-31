# Ghana SHS Learning Management System

A web-based Learning Management System for Ghanaian Senior High Schools. The platform provides role-based portals for platform administrators, institution administrators, teachers, students, and parents, and supports multiple institutions from a single deployment.

The repository contains the REST API (`lms-api`), the static frontend (`lms-frontend`), database initialization scripts (`db/init`), and a Docker Compose stack for running everything locally.

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545?logo=mariadb&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?logo=javascript&logoColor=black)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3-06B6D4?logo=tailwindcss&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Repository Structure](#repository-structure)
- [Quick Start with Docker](#quick-start-with-docker)
- [Local Development](#local-development)
- [Environment Variables](#environment-variables)
- [Database](#database)
- [API Reference](#api-reference)
- [Roles and Permissions](#roles-and-permissions)
- [Frontend](#frontend)
- [Background Jobs](#background-jobs)
- [Utility Scripts](#utility-scripts)
- [Testing and Verification](#testing-and-verification)
- [Troubleshooting](#troubleshooting)
- [Security Notes](#security-notes)
- [Project Status and Known Gaps](#project-status-and-known-gaps)
- [License](#license)

---

## Overview

The system is organized into the following components:

| Component | Path | Description |
| --- | --- | --- |
| REST API | `lms-api/` | PHP 8.2 API with a custom front controller, JWT authentication, repositories, and role-aware controllers |
| Frontend | `lms-frontend/` | Static HTML/CSS/JavaScript application served by Nginx or any static file server |
| Database | `db/init/` | Schema (`01-lms.sql`) and seed data (`02-insert.sql`) for MariaDB/MySQL |
| Stack | `docker-compose.yml` | MariaDB, PHP/Apache API, Nginx frontend, and MailHog for local email testing |

All API responses are JSON and use a consistent envelope:

```json
{
  "success": true,
  "message": "Success",
  "data": {},
  "timestamp": "2026-01-01 12:00:00"
}
```

Paginated endpoints add a `pagination` object with `total`, `per_page`, `current_page`, `last_page`, `from`, and `to`.

---

## Features

- Multi-institution support with platform-level (super admin) and institution-level administration
- Five roles: `super_admin`, `admin`, `teacher`, `student`, `parent`
- JWT authentication with access/refresh tokens, password reset, and login activity tracking
- Student, teacher, parent, class, subject, program, and academic year/semester management
- Course content, sections, materials, enrollments, and lesson plans
- Assignments and submissions with file uploads
- Assessments, assessment categories, quizzes, and quiz submissions
- Attendance tracking
- Ghana WAEC grading scale support (A1–F9), grade categories, grade scales, and grade reports
- Announcements, events, notifications, direct messages, and chat rooms
- Dashboards and analytics per role
- Scheduled report generation and email delivery
- PDF/Excel export support in the frontend (jsPDF, SheetJS)
- Activity, login, and error logging

---

## Tech Stack

**Backend**

- PHP 8.2 (`>=8.0` required by Composer), PDO/MySQL, mbstring, JSON
- Custom router and front controller (`lms-api/public/index.php`)
- PSR-4 autoloading: `App\` → `lms-api/src/`
- `firebase/php-jwt` for tokens, `vlucas/phpdotenv` for configuration, `phpmailer/phpmailer` for email

**Database**

- MariaDB 10.11 in Docker; MariaDB 10.4+ works locally
- 71 tables covering users, institutions, academics, courses, assessments, messaging, and auditing

**Frontend**

- Static HTML5, CSS3, and vanilla ES6+ JavaScript
- Tailwind CSS 3 (build pipeline in `lms-frontend`)
- Role-specific page trees under `superadmin/`, `admin/`, `teacher/`, `student/`, `parent/`, plus `auth/` and `common/`

**Infrastructure**

- Docker Compose: MariaDB, Apache + PHP API, Nginx frontend, MailHog
- Nginx serves the frontend with SPA-style fallback to `index.html`

---

## Repository Structure

```text
Learning/
├── docker-compose.yml            # Local full-stack environment
├── .env.docker.example           # Database credentials for Docker Compose
├── .gitignore                    # Keeps secrets, uploads, logs, and generated files out of Git
├── db/
│   └── init/
│       ├── 01-lms.sql            # Schema (71 tables)
│       └── 02-insert.sql         # Seed data (roles, permissions, users, demo records)
├── lms-api/
│   ├── public/                   # Web root: index.php (front controller), router.php, .htaccess
│   ├── src/
│   │   ├── Config/               # Database connection
│   │   ├── Controllers/          # Request handlers
│   │   ├── Middleware/           # Auth, authorization, role, error handling
│   │   ├── Repositories/         # Data access layer
│   │   ├── Routes/api.php        # Route table (method + path → controller action)
│   │   ├── Services/             # Email service and assets
│   │   └── Utils/                # JWT, responses, validation, UUID helpers
│   ├── config/                   # Database, CORS, and JWT configuration
│   ├── migrations/               # Standalone, idempotent migration scripts
│   ├── scripts/                  # Seeders, report scheduler worker, installers
│   ├── uploads/                  # Uploaded files (served at /uploads/...)
│   ├── logs/                     # Application logs
│   ├── vendor/                   # Composer dependencies
│   ├── .env.example              # Local API configuration template
│   ├── Dockerfile
│   └── composer.json
├── lms-frontend/
│   ├── index.html                # Landing page
│   ├── auth/                     # Login, forgot/reset password
│   ├── superadmin/               # Dashboard + page/ tree
│   ├── admin/                    # Dashboard + page/ tree
│   ├── teacher/                  # Dashboard + page/ tree
│   ├── student/                  # Dashboard + page/ tree
│   ├── parent/                   # Dashboard + page/ tree
│   ├── common/                   # Shared pages (calendar, notifications)
│   ├── policy/                   # Legal and policy pages
│   ├── assets/
│   │   ├── css/                  # Base, landing, login, policy styles + Tailwind input
│   │   ├── js/                   # config.js, api.js, auth.js, components.js, utils.js, logger.js
│   │   ├── img/                  # Images
│   │   └── vendor/               # Local copies of export libraries
│   ├── scripts/generate-env.js   # Generates assets/js/env.js from .env
│   ├── tailwind.config.js
│   ├── nginx.conf
│   └── Dockerfile
└── logs/                         # Root-level log files
```

> `lms-frontend/README.md` and `lms-frontend/DEVELOPMENT-PLAN.md` are older, frontend-specific planning documents. This root README reflects the current code.

---

## Quick Start with Docker

### Prerequisites

- Docker Desktop (or Docker Engine with Compose v2)

### 1. Configure environment files

```bash
cp .env.docker.example .env.docker
cp lms-api/.env.docker.example lms-api/.env.docker
```

Edit both files:

- `.env.docker` — database name, user, password, and root password.
- `lms-api/.env.docker` — set a strong `JWT_SECRET` (at least 32 characters), and keep `DB_HOST=db`, `DB_PORT=3306`, `DB_NAME`, `DB_USER`, and `DB_PASS` consistent with `.env.docker`. The template already points mail at the `mailhog` service.

The frontend's API URL is set by the `frontend.build.args` section of `docker-compose.yml` (default `http://localhost:8081`).

### 2. Build and start

```bash
docker compose --env-file .env.docker up --build
```

Compose interpolates `${...}` values from `.env` by default; `--env-file .env.docker` makes it use the same file that the `db` service receives via `env_file`.

### 3. Open the applications

| Service | URL | Notes |
| --- | --- | --- |
| Frontend | http://localhost:8082 | Nginx static site |
| API | http://localhost:8081/api | Apache + PHP |
| MailHog UI | http://localhost:8025 | Captured outbound email |
| MailHog SMTP | `localhost:1025` | Configure `MAIL_HOST`/`MAIL_PORT` if you want the API to send here |
| MariaDB | `localhost:3307` | Host port maps to container port 3306 |

### 4. Stop or reset

```bash
docker compose down       # stop containers, keep database volume
docker compose down -v    # stop and DELETE the database volume
```

On first start, MariaDB runs everything in `db/init` against an empty data directory. If the volume already contains data, the init scripts are skipped.

---

## Local Development

### Prerequisites

- PHP 8.0+ with `pdo`, `pdo_mysql`, `mbstring`, and `json` extensions
- Composer (or the bundled `lms-api/composer.phar`)
- MariaDB 10.4+ or MySQL 8
- Node.js 18+ (for frontend environment generation and Tailwind)
- A static file server (Python, Node, or PHP built-in server)

### 1. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p lms < db/init/01-lms.sql
mysql -u root -p lms < db/init/02-insert.sql
```

`02-insert.sql` seeds roles, permissions, a platform super admin, and demo institution data. Review it before importing into any shared environment.

> **Do not use the seeded super-admin outside local development.** Its password hash is committed to this repository, so the password must be treated as known. Provision or rotate the account with:
>
> ```bash
> php lms-api/scripts/create_superadmin.php --email=you@example.com
> ```
>
> The script generates a random password and prints it once. Add `--force` to rotate an existing account.

### 2. Configure and start the API

Create `lms-api/.env` from the template and adjust the local values:

```bash
cp lms-api/.env.example lms-api/.env
```

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lms
DB_USER=root
DB_PASS=

JWT_SECRET=replace-with-a-long-random-secret-min-32-chars
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
```

Install dependencies if `vendor/` is missing (from the repository root):

```bash
cd lms-api
composer install
cd ..
```

Start the API with the development router (it handles `/api/*`, `/uploads/*`, and CORS preflight). Run this from the repository root:

```bash
php -S localhost:8000 -t lms-api/public lms-api/public/router.php
```

The API is available at `http://localhost:8000/api`.

> For Apache/XAMPP, point the document root at `lms-api/public`. The bundled `.htaccess` files route requests to `public/index.php`.

### 3. Configure and start the frontend

```bash
cd lms-frontend
cp .env.example .env
# Edit .env: set API_BASE_URL=http://localhost:8000
node scripts/generate-env.js
```

`generate-env.js` writes `assets/js/env.js`, which is loaded before `assets/js/config.js`. Regenerate it whenever `.env` changes.

Install frontend dependencies and build Tailwind:

```bash
npm install
npm run build          # or: npm run tailwind:dev (watch mode)
```

Serve the static files:

```bash
# From lms-frontend/
python -m http.server 3000
# or: npx http-server -p 3000
# or: php -S localhost:3000
```

Open `http://localhost:3000`. The landing page links to the login page under `auth/login.html`; sign in with a user from `db/init/02-insert.sql`.

---

## Environment Variables

Never commit real credentials. The `.example` files are templates only.

### Root (`.env.docker`)

Used by the `db` service in `docker-compose.yml`.

| Variable | Purpose |
| --- | --- |
| `DB_ROOT_PASSWORD` | MariaDB root password |
| `DB_NAME` | Database name |
| `DB_USER` | Application database user |
| `DB_PASS` | Application database password |
| `API_BASE_URL` | API base URL used by the Docker Compose configuration |

### API (`lms-api/.env`)

| Variable | Purpose |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `JWT_SECRET` | Signing key — use a long random value |
| `JWT_ISSUER`, `JWT_AUDIENCE` | Token claims |
| `JWT_ACCESS_EXPIRY` | Access token lifetime in seconds (default `3600`) |
| `JWT_REFRESH_EXPIRY` | Refresh token lifetime in seconds (default `604800`) |
| `APP_ENV`, `APP_DEBUG` | Runtime mode and error display |
| `APP_URL`, `FRONTEND_URL` | Public URLs used in links and emails |
| `ALLOWED_ORIGINS` | Comma-separated CORS allowlist (`*` allows all) |
| `MAIL_*` | SMTP host, port, credentials, and sender identity |
| `STORAGE_TYPE`, `STORAGE_PATH` | Upload storage configuration |
| `MAX_UPLOAD_SIZE` | Maximum upload size in bytes (default 10 MB) |
| `RATE_LIMIT_REQUESTS`, `RATE_LIMIT_WINDOW` | Rate limiting configuration |
| `DEFAULT_PAGE_SIZE`, `MAX_PAGE_SIZE` | Pagination limits |
| `REPORT_SCHEDULER_AUTO_TRIGGER` | Trigger the report worker from HTTP requests |
| `REPORT_SCHEDULER_TRIGGER_COOLDOWN_SECONDS` | Minimum seconds between auto-trigger attempts |

### Frontend (`lms-frontend/.env`)

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `development`, `staging`, or `production` |
| `API_BASE_URL` | Base URL of the API (no trailing slash) |
| `MAIL_*` | Optional client-side mail configuration values |

Only `APP_ENV` and `API_BASE_URL` are written to `assets/js/env.js` by the generator.

---

## Database

- **Schema:** `db/init/01-lms.sql` — creates 71 tables with `CREATE TABLE IF NOT EXISTS`, foreign keys, and indexes.
- **Seed data:** `db/init/02-insert.sql` — roles, permissions, users, and demo records.
- **Migrations:** `lms-api/migrations/*.php` — standalone scripts. They are not run automatically; run them individually from `lms-api/`:

```bash
php migrations/add_is_published_to_events.php
```

Most migrations check whether a column or table already exists and skip safely when it does.

### Main table groups

| Area | Tables |
| --- | --- |
| Identity and access | `users`, `admins`, `roles`, `permissions`, `role_permissions`, `user_roles`, `password_reset_tokens`, `login_activity` |
| Institutions | `institutions`, `institution_settings`, `subscription_plans` |
| Academics | `academic_years`, `semesters`, `grade_levels`, `programs`, `classes`, `subjects`, `class_subjects`, `students`, `teachers`, `parents`, `parent_students` |
| Courses and content | `courses`, `course_sections`, `course_content`, `course_materials`, `course_enrollments`, `course_schedules`, `course_reviews`, `lesson_plans`, `lesson_plan_materials` |
| Assessment | `assignments`, `assignment_submissions`, `assessments`, `assessment_categories`, `assessment_submissions`, `quizzes`, `quiz_questions`, `quiz_question_options`, `quiz_submissions`, `quiz_submission_answers`, `results`, `grade_scales`, `grade_categories`, `grade_reports`, `grade_report_details` |
| Attendance and events | `attendance`, `events`, `announcements`, `announcement_reads` |
| Communication | `messages`, `notifications`, `notification_reads`, `chat_rooms`, `chat_messages`, `chat_room_members`, `chat_attachments`, `chat_message_reads` |
| Operations | `system_settings`, `error_logs`, `report_schedules`, `report_schedule_runs`, `schema_migrations`, and per-role activity tables |

> **Destructive operations:** `docker compose down -v` deletes the database volume. Dropping tables or re-importing seed data into an existing database can overwrite data. Back up before running migrations on real data.

---

## API Reference

- **Base URL (Docker):** `http://localhost:8081/api`
- **Base URL (local PHP server):** `http://localhost:8000/api`
- **Routes:** `lms-api/src/Routes/api.php` (~448 routes)
- **Auth:** `Authorization: Bearer <access_token>` for protected routes

The front controller strips a leading `/api` prefix, so `GET /api/students` and `GET /students` resolve to the same route.

### Authentication

`POST /auth/login` accepts either a username or an email address in the `login` field.

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"<username-or-email>","password":"<password>"}'
```

Use the returned access token on subsequent requests:

```bash
curl http://localhost:8000/api/students \
  -H "Authorization: Bearer <access_token>"
```

Other auth endpoints:

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/auth/register` | Register a user |
| `POST` | `/auth/login` | Obtain tokens |
| `POST` | `/auth/refresh` | Refresh an access token |
| `POST` | `/auth/logout` | Invalidate the session |
| `GET` | `/auth/me` | Current authenticated user |
| `POST` | `/auth/change-password` | Change password (authenticated) |
| `POST` | `/auth/forgot-password` | Request a reset link |
| `POST` | `/auth/reset-password` | Reset with a token |

### Endpoint groups

Representative paths; see `src/Routes/api.php` for the complete list.

| Group | Example paths |
| --- | --- |
| Dashboards | `/dashboard/superadmin`, `/dashboard/admin`, `/dashboard/teacher`, `/dashboard/student`, `/dashboard/parent` |
| Students | `/students`, `/students/{uuid}`, `/students/{uuid}/courses`, `/students/enroll` |
| Teachers | `/teachers`, `/teachers/{uuid}`, `/teachers/{uuid}/schedule`, `/teachers/{uuid}/performance` |
| Users and roles | `/users`, `/users/{uuid}/roles`, `/roles`, `/permissions` |
| Institutions | `/institutions`, `/institutions/{uuid}/settings` |
| Academics | `/academic-years`, `/semesters`, `/grade-levels`, `/programs`, `/classes`, `/subjects` |
| Courses | `/courses`, `/course-sections`, `/course-content`, `/course-materials`, `/enrollments` |
| Attendance | `/attendance`, `/attendance/summary`, `/attendance/bulk`, `/students/{studentId}/attendance/stats` |
| Assignments | `/assignments`, `/assignment-submissions`, `/submissions/{submissionId}/grade` |
| Assessments | `/assessments`, `/assessment-categories`, `/results`, `/courses/{courseId}/students/{studentId}/final-score` |
| Quizzes | `/quizzes`, `/quiz-questions`, `/quiz-submissions` |
| Grading | `/grade-scales`, `/grade-categories`, `/grade-reports` |
| Communication | `/messages`, `/notifications`, `/chat`, `/announcements`, `/events` |
| Reports | `/report-schedules` |
| Uploads | `/upload`, `/upload/multiple`, `/upload/{category}/{filename}` |
| Auditing | `/user-activity`, `/admin-activity`, `/teacher-activity`, `/student-activity`, `/parent-activity`, `/superadmin-activity`, `/login-activity`, `/error-logs` |
| System | `/system/settings`, `/subscriptions` |

### Response conventions

| Status | Meaning |
| --- | --- |
| `200` / `201` | Success |
| `400` | Bad request |
| `401` | Missing, invalid, or expired token |
| `403` | Authenticated but not permitted |
| `404` | Unknown endpoint or resource |
| `422` | Validation failure (`errors` array included) |
| `500` | Server error |

---

## Roles and Permissions

| Role | Scope |
| --- | --- |
| `super_admin` | Platform-wide: institutions, platform users, roles, subscriptions, system settings, audit logs |
| `admin` | Single institution: students, teachers, classes, subjects, programs, attendance, reports, settings |
| `teacher` | Assigned classes and subjects: attendance, assignments, assessments, quizzes, materials, grades |
| `student` | Own classes, materials, assignments, assessments, attendance, and grades |
| `parent` | Linked children: performance, attendance, assignments, schedules, announcements |

Authorization is enforced in the API by `AuthMiddleware`, `AuthorizationMiddleware`, and `RoleMiddleware`. Role and permission assignments are stored in `roles`, `permissions`, `role_permissions`, and `user_roles`.

---

## Frontend

### Page structure

Each role has a `dashboard.html` entry point and a `page/` directory for feature screens:

```text
lms-frontend/
├── index.html                 # Landing page
├── auth/                      # login, forgot-password, reset-password, resend-reset
├── superadmin/                # dashboard + institutions, users, roles, reports, system, logs
├── admin/                     # dashboard + students, teachers, classes, subjects, grades, reports
├── teacher/                   # dashboard + classes, assignments, assessments, quizzes, grading
├── student/                   # dashboard + assignments, quizzes, grades, materials, timetable
├── parent/                    # dashboard + children, performance, attendance, messages
├── common/                    # calendar, notifications
└── policy/                    # privacy, terms, cookies, GDPR
```

### JavaScript modules

| File | Responsibility |
| --- | --- |
| `assets/js/env.js` | Generated runtime configuration (`window.ENV_CONFIG`) |
| `assets/js/config.js` | App constants, API endpoints, defaults |
| `assets/js/api.js` | Fetch wrapper, token handling, error handling |
| `assets/js/auth.js` | Login, session, role-based redirects, logout |
| `assets/js/components.js` | Shared UI components (sidebar, header, modals) |
| `assets/js/utils.js` | Formatting, validation, export helpers |
| `assets/js/logger.js` | Client-side logging |

### Build commands

Run from `lms-frontend/`:

| Command | Purpose |
| --- | --- |
| `npm run build` | Minified Tailwind build |
| `npm run dev` | Tailwind watch mode |
| `npm run vendor:copy` | Copy `xlsx` and `jspdf` bundles into `assets/vendor/` |

---

## Background Jobs

Scheduled reports are processed by `lms-api/scripts/report_schedule_worker.php`. Run it from cron, Task Scheduler, or manually:

```bash
cd lms-api
php scripts/report_schedule_worker.php
```

Helpers:

- `scripts/install_scheduler_windows.ps1` — registers a Windows scheduled task
- `scripts/install_scheduler_unix.sh` — installs a cron entry
- `scripts/report_schedule_loop.php` — long-running loop
- `scripts/verify_schedules.php` — inspects due schedules
- `scripts/set_schedules_due_now.php` — test helper

The API can also trigger the worker opportunistically on HTTP requests. Control this with `REPORT_SCHEDULER_AUTO_TRIGGER` and `REPORT_SCHEDULER_TRIGGER_COOLDOWN_SECONDS`.

---

## Utility Scripts

All paths are relative to `lms-api/`.

| Script | Purpose |
| --- | --- |
| `scripts/seed_analytics_data.php` | Generate sample assignments and submissions for teacher analytics |
| `scripts/seed_analytics_data.sql` | SQL equivalent of the analytics seed |
| `scripts/seed_sample_schedules.php` | Create sample report schedules |
| `scripts/create_report_schedules_tables.php` | Create report schedule tables |
| `scripts/email-template-preview.html` | Preview the report email template |
| `migrations/*.php` | Schema migrations (run individually) |

Additional documentation: `lms-api/scripts/README-ANALYTICS-SEEDING.md`.

---

## Testing and Verification

There is currently **no automated test suite or CI pipeline** in this repository. Verify changes manually and with static checks.

### Static checks

```bash
# Syntax-check the API entry point and a controller
php -l lms-api/public/index.php
php -l lms-api/src/Controllers/AuthController.php

# Validate the frontend environment generator
node lms-frontend/scripts/generate-env.js
```

### Smoke checks

```bash
# API reachable
curl -i http://localhost:8000/api/auth/me

# Login flow (use a seeded account)
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"<username-or-email>","password":"<password>"}'

# Database reachable
mysqladmin -h 127.0.0.1 -P 3306 -u root -p ping
```

### Manual checklist

- [ ] Landing page and login load without console errors
- [ ] Login redirects to the correct role dashboard
- [ ] Authenticated API calls return data and handle `401` by logging out
- [ ] CRUD screens load, paginate, and show validation errors
- [ ] File uploads succeed and are served from `/uploads/...`
- [ ] Notifications and unread counts update
- [ ] PDF/Excel exports produce valid files
- [ ] Layout works on mobile, tablet, and desktop
- [ ] Role restrictions are enforced when navigating directly to another role's pages

---

## Troubleshooting

| Symptom | Likely cause and fix |
| --- | --- |
| `401 Unauthorized` on every request | Missing/expired token. Log in again; confirm `JWT_SECRET` has not changed. |
| Browser CORS error | Add the frontend origin to `ALLOWED_ORIGINS` in `lms-api/.env` and restart the API. |
| Frontend calls the wrong API URL | `assets/js/env.js` is stale. Update `lms-frontend/.env` and run `node scripts/generate-env.js`. |
| `env.js` missing or `undefined` config | Run the generator; make sure `env.js` is loaded before `config.js`. |
| API returns `Endpoint not found` | Check the method and path in `lms-api/src/Routes/api.php`; the router is method-sensitive. |
| Uploads return 404 | Ensure `lms-api/uploads/` exists and the web server can read it. The dev router serves `/uploads/*`. |
| Cannot connect to the database | Docker maps port `3307`; local MariaDB usually uses `3306`. Match `DB_PORT` to the actual server. |
| Docker init scripts did not run | They only run on an empty data volume. Use `docker compose down -v` to reset (destroys data). |
| Emails not delivered | In Docker, `MAIL_HOST` should be `mailhog` and `MAIL_PORT` `1025`; on the host, use `localhost`/`1025`. Check the MailHog UI at http://localhost:8025. |
| `Class not found` / autoload errors | Run `composer install` in `lms-api/` and confirm the `App\` PSR-4 mapping. |

---

## Security Notes

- **Rotate every secret that was ever committed.** Environment files were previously tracked in this public repository. They are now ignored and untracked, but they remain in Git history until that history is purged. Treat the database passwords, JWT secrets, and SMTP credentials as compromised: revoke SMTP app passwords, rotate the JWT secret, and change database passwords.
- **Keep secrets out of the frontend.** `lms-frontend` must only receive `APP_ENV`, `API_BASE_URL`, and (optionally) local demo credentials through generated `env.js`. Never place SMTP credentials or other server-side secrets in frontend files.
- **Set a strong `JWT_SECRET`.** Use at least 32 random characters and never reuse it across environments.
- **Restrict CORS.** Prefer an explicit `ALLOWED_ORIGINS` list over `*` outside local development.
- **Serve over HTTPS in production** and set `APP_ENV=production`, `APP_DEBUG=false`.
- **Validate uploads** on the server (type, size, and destination) in addition to client-side checks.
- **Back up the database** before running migrations or importing seed data into an existing environment.
- **Review the seed data** before using it outside local development; it creates known accounts and demo records.
- **Purge history and force-push only with team coordination.** Rewriting history requires collaborators to re-clone and does not remove copies held by forks, clones, or caches — rotation is still mandatory.

### Secret rotation checklist

1. Revoke the Gmail app passwords used by `MAIL_*` and review the account's recent activity.
2. Generate a new `JWT_SECRET` (`php -r "echo bin2hex(random_bytes(32));"`); all sessions are invalidated.
3. Change the MariaDB root and application user passwords; update `.env.docker` / `lms-api/.env`.
4. Rotate the seeded super-admin: `php lms-api/scripts/create_superadmin.php --email=you@example.com --force`.
5. Regenerate `assets/js/env.js` and rebuild the frontend image.
6. Confirm `git ls-files` no longer lists `.env`, uploads, or logs.

---

## Project Status and Known Gaps

The application is functional end to end (authentication, role dashboards, academic management, assessments, messaging, and reporting), but the repository has some maintenance gaps:

- No automated tests, linter configuration, or CI workflow.
- Secrets committed before `.gitignore` was added remain in Git history until the history is purged; rotate them regardless.
- Uploads and logs are no longer tracked, but previously committed copies remain in history.
- The seed super-admin account uses a fixed password hash and has no forced password change.
- `lms-frontend/README.md` and `DEVELOPMENT-PLAN.md` predate the current page structure.
- Database changes are applied through individual migration scripts rather than a tracked migration runner.

---

## License

Released under the [Apache License 2.0](LICENSE).
