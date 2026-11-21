# Fresh-install database files

MariaDB runs every file in `/docker-entrypoint-initdb.d/` in filename order, **only on an empty data volume**. The final set must be:

| File | Source |
| --- | --- |
| `01-lms.sql` | Copy from the Learning repo: `db/init/01-lms.sql` |
| `02-rbac.sql` | This folder |
| `03-refresh-tokens.sql` | Copy from the Learning repo: `db/init/03-refresh-tokens.sql` |
| `04-quiz-submission-questions.sql` | Copy from the Learning repo: `db/init/04-quiz-submission-questions.sql` |
| `05-question-bank.sql` | Copy from the Learning repo: `db/init/05-question-bank.sql` |
| `06-question-bank-metadata.sql` | Copy from the Learning repo: `db/init/06-question-bank-metadata.sql` |
| `07-production-settings.sql` | This folder |

**Do NOT copy the repo's `db/init/02-insert.sql`.** It is demo data: it contains a demo institution, a super-admin account whose password hash is committed in the repository, and a settings row with `allow_registration: 1`. `02-rbac.sql` keeps the roles/permissions needed by the application without those risks.

## After first start

Apply the additive migration that the current schema needs:

```bash
cd /opt/learning
docker compose exec api php migrations/add_course_section_to_questions.php
```

This adds the lesson-plan Topic / Unit / Week link and is safe on a fresh database. Then create the real super admin:

```bash
docker compose exec api php scripts/create_superadmin.php --email=ADMIN_EMAIL
```

## If the volume already has data

Init files will be skipped. Do not use `docker compose down -v` to force them — that destroys data. Restore an SQL dump instead, or apply the files manually with `mysql < file.sql`.
