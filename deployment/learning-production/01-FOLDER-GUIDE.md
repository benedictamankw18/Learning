# Folder guide — what each file is and when to use it

Copy this whole `learning-deployment` folder to `C:\Learning-Deployment\` on the Windows Server.

```text
learning-deployment/
├── 00-START-HERE.md                  ← MASTER RUNBOOK. Read this first, top to bottom.
├── 01-FOLDER-GUIDE.md                ← This file.
├── windows/
│   ├── Check-ProductionReadiness.ps1 ← Run before creating the VM. Checks RAM/disk/virtualisation.
│   ├── Monitor-RDS.ps1               ← Run for 60 minutes during peak RDS use. Capacity gate.
│   ├── Create-LearningDockerVM.ps1   ← Creates the Generation 2 Ubuntu VM with auto-start.
│   ├── Configure-DNS.ps1             ← Creates zone geslab.local and A record lms → 192.168.10.196.
│   └── Setup-UsbBackupShare.ps1      ← Creates the restricted SMB share on the external USB drive.
├── ubuntu/
│   ├── setup-docker.sh               ← Installs Docker Engine + log rotation + boot enable.
│   ├── proxy-nginx.conf              ← Port 80 reverse proxy: / → frontend, /api/ /uploads/ → API.
│   ├── maria-production.cnf          ← MariaDB tuning + power-loss crash-safety settings.
│   ├── production.env.example        ← Template for /opt/learning/.env.docker.
│   ├── api-production.env.example    ← Template for /opt/learning/lms-api/.env.docker.
│   ├── backup.sh                     ← Database + uploads backup to the USB share, with SHA-256.
│   ├── restore.sh                    ← Restores a backup (stops the API first).
│   └── check-health.sh               ← One-command health report.
├── database/init-production/
│   ├── 02-rbac.sql                   ← Roles/permissions only (no demo users or institutions).
│   ├── 07-production-settings.sql    ← Public registration OFF, correct site_url, reference plans.
│   └── README.md                     ← Which repo files to copy alongside these two.
├── compose/
│   └── docker-compose.production.yml ← Production stack (db, api, frontend, proxy, MailHog).
└── docs/
    ├── ARCHITECTURE.md               ← Diagram and port-exposure table.
    ├── POWER-CUT-PROTECTION.md       ← What is protected, what is not, what to do after a cut.
    ├── TROUBLESHOOTING.md            ← Fixes for the common failures.
    └── CHECKLIST.md                  ← Printable go-live checklist.
```

## What you do NOT need from this folder

- You do **not** use any Docker Desktop installer (`docker.exe`) on Windows Server.
- You do **not** use the existing `docker-compose.yml` from the repo for production — the `compose/` file here replaces it and adds the port-80 proxy.
- You do **not** copy the repo's `db/init/02-insert.sql` — it contains a demo super admin with a committed password hash. Use `database/init-production/` instead.

## What you still need from the Learning repository

Copy these folders/files from your local `C:\xampp\htdocs\Learning` repo into the VM:

- `lms-api/` (whole folder, builds the API image)
- `lms-frontend/` (whole folder, builds the frontend image)
- `db/init/01-lms.sql`
- `db/init/03-refresh-tokens.sql`
- `db/init/04-quiz-submission-questions.sql`
- `db/init/05-question-bank.sql`
- `db/init/06-question-bank-metadata.sql`

Those five SQL files plus the two in `database/init-production/` form the complete fresh-install set.

## Order of use

1. `windows/Monitor-RDS.ps1` (capacity gate)
2. `windows/Check-ProductionReadiness.ps1`
3. `windows/Create-LearningDockerVM.ps1`
4. `windows/Configure-DNS.ps1`
5. Ubuntu install (manual, in the runbook)
6. `ubuntu/setup-docker.sh`
7. `compose/` + `ubuntu/` configs + `database/init-production/` (Phase 5)
8. `windows/Setup-UsbBackupShare.ps1` + `ubuntu/backup.sh` (Phase 7)
9. `docs/CHECKLIST.md` (Phase 10)
