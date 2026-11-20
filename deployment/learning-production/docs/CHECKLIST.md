# Go-live checklist — Learning LMS at GESLAB

Print this or save a copy outside the server. Tick each item only when verified.

## Before installation

- [ ] Ubuntu Server 24.04 ISO downloaded and path recorded (`ISO_PATH`)
- [ ] Docker Desktop (`docker.exe`) **not** installed on Windows Server
- [ ] LMS name confirmed: `lms.geslab.local`
- [ ] Network values recorded: `WINDOWS_IP`, `GATEWAY_IP`, `USB_DRIVE`
- [ ] Recent full backup of the Windows Server exists

## Capacity gate

- [ ] `Monitor-RDS.ps1` ran for 60 minutes with all 40 students active
- [ ] `Min_Available_GB ≥ 8`
- [ ] `Max_CPU_Pct < 80`
- [ ] `P95_Read_Latency_ms < 20`

## Windows Server and Hyper-V

- [ ] Hyper-V installed; `vmms` service running
- [ ] External switch `Docker-External` created on the LAN adapter
- [ ] Zone `geslab.local` and A record `lms` → `192.168.10.196` created
- [ ] `Resolve-DnsName lms.geslab.local` returns `192.168.10.196` on the server
- [ ] `nslookup lms.geslab.local` returns `192.168.10.196` on a student PC
- [ ] VM `Learning-Docker`: Gen 2, 4 GB fixed, 2 vCPU, 80 GB dynamic VHDX
- [ ] VM automatic start = Start, delay 60, automatic stop = Shut Down
- [ ] BIOS *AC Recovery / Restore on AC Power Loss* = **Power On**

## Ubuntu VM

- [ ] Hostname `learning-docker`
- [ ] IP `192.168.10.196` via DHCP reservation (or documented static config)
- [ ] OpenSSH installed
- [ ] ISO detached, VHDX first in boot order
- [ ] Docker Engine installed from official repo; `docker compose version` works
- [ ] `daemon.json` has log rotation and `live-restore`
- [ ] `systemctl is-enabled docker` → `enabled`

## LMS deployment

- [ ] `/opt/learning` contains Compose file, proxy config, env files, init SQL, app source
- [ ] `.env.docker` and `lms-api/.env.docker` filled with unique secrets, `chmod 600`
- [ ] `ALLOWED_ORIGINS=http://lms.geslab.local`
- [ ] `API_BASE_URL=http://lms.geslab.local` was set **before** the frontend build
- [ ] Frontend image was built with `--build` so `env.js` is regenerated with `APP_ENV=production` (no demo credentials)
- [ ] Login page shows no quick-login / demo buttons
- [ ] `docker compose config` validates without errors
- [ ] `db` healthy; `api`, `frontend`, `proxy`, `mailhog` running
- [ ] `curl -i http://127.0.0.1/api/auth/me` → 401
- [ ] `curl -I http://127.0.0.1/` → 200
- [ ] `curl -I http://127.0.0.1:8025/` → 200 (MailHog UI)
- [ ] `MAIL_HOST=mailhog`, `MAIL_PORT=1025` in `lms-api/.env.docker`
- [ ] A test email appears in MailHog at `http://192.168.10.196:8025`
- [ ] MariaDB port 3306 and SMTP port 1025 are **not** published to the LAN
- [ ] Ports 80 (students) and 8025 (admin only) are the only published ports

## Fresh-install setup

- [ ] Demo init file `02-insert.sql` was **not** used
- [ ] Real super admin created with `create_superadmin.php`; password stored securely
- [ ] Institution created through the UI
- [ ] Public registration confirmed **disabled**
- [ ] Teacher and student accounts created for the pilot

## Backups and power-cut protection

- [ ] USB drive connected; `LearningBackups$` share created with restricted account
- [ ] `/mnt/learning-backups` mounted from fstab with `nofail,x-systemd.automount`
- [ ] `backup.sh` ran successfully once; file listed and SHA-256 verified
- [ ] Cron entries added (every 2 hours + final school-day copy)
- [ ] `docs/POWER-CUT-PROTECTION.md` read and understood

## Automatic startup proof

- [ ] Normal Windows Shut Down performed
- [ ] Server powered on; **no login performed**
- [ ] VM started by itself
- [ ] Docker and all five containers (`db`, `api`, `frontend`, `proxy`, `mailhog`) started by themselves
- [ ] `http://lms.geslab.local` loaded from a student PC after reboot
- [ ] Login succeeded for a teacher and a student

## Go-live

- [ ] Pilot with 3–5 students passed (login, quizzes, grades, question bank)
- [ ] Rolled out to all 40 students
- [ ] RDS monitoring repeated during first full teaching day
- [ ] Troubleshooting document saved where teachers can find it
