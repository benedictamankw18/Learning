# Deployment Documentation

This directory contains the deployment and operations guides for the Ghana SHS LMS.
The root [`README.md`](../../README.md) is the project overview; the documents here
are the detailed procedures.

## Which guide do I need?

| I want to… | Read |
| --- | --- |
| Run the whole stack on my machine with one command | [`deploy-docker.md`](deploy-docker.md) |
| Develop the API or frontend directly on my machine (XAMPP, `php -S`, static server) | [`deployed-development.md`](deployed-development.md) |
| Deploy to a real server that users will access over the internet | [`production.md`](production.md) |
| Back up, migrate, monitor, or roll back a running system | [`operations.md`](operations.md) |

Related documentation:

- [`../security/phase7-secret-rotation.md`](../security/phase7-secret-rotation.md) — rotating
  compromised secrets and purging Git history.
- [`../assessment/question-bank.md`](../assessment/question-bank.md) — the teacher-private
  question bank, its init/migration files, and ownership rules.
- [`../assessment/question-import-export.md`](../assessment/question-import-export.md) — the
  CSV question import/export format and workflow.
- The **Security Notes** section of the root [`README.md`](../../README.md).

## Components

| Component | Path | Runtime |
| --- | --- | --- |
| REST API | `lms-api/` | PHP 8.2 + Apache (Docker) or PHP built-in server (development) |
| Frontend | `lms-frontend/` | Static HTML/CSS/JS served by Nginx or any static file server |
| Database | `db/init/` | MariaDB 10.11 (Docker) or MariaDB 10.4+ / MySQL 8 |
| Orchestration | `docker-compose.yml` | MariaDB, API, frontend, MailHog |

## Default ports

These are the values in the repository's `docker-compose.yml` and development commands.
Change them for production if they conflict with other services.

| Service | Docker URL | Local development URL |
| --- | --- | --- |
| Frontend | `http://localhost:8082` | `http://localhost:3000` |
| API | `http://localhost:8081/api` | `http://localhost:8000/api` |
| MariaDB | `localhost:3307` | `localhost:3306` |
| MailHog UI | `http://localhost:8025` | n/a |
| MailHog SMTP | `localhost:1025` | n/a |

## Conventions used in these guides

- Commands are run from the **repository root** unless a step says otherwise.
- Values in angle brackets (`<like-this>`) are placeholders you must replace.
- Never commit `.env` files or real credentials. Only the `.example` templates belong in Git.
- `docker compose` refers to Compose v2 (`docker compose`, not `docker-compose`).

## Environment files at a glance

| File | Used by | Committed? |
| --- | --- | --- |
| `.env.docker` | Docker Compose (`db` service and `${...}` interpolation) | No — copy from `.env.docker.example` |
| `lms-api/.env.docker` | API container | No — copy from `lms-api/.env.docker.example` |
| `lms-api/.env` | API outside Docker | No — copy from `lms-api/.env.example` |
| `lms-frontend/.env` | Frontend env generator (local development) | No — copy from `lms-frontend/.env.example` |
| `lms-frontend/.env.docker` | Frontend Docker build (optional) | No — copy from `lms-frontend/.env.docker.example` |

`lms-frontend/assets/js/env.js` is generated from the frontend `.env` (or from build args in
Docker) and is also not committed.
