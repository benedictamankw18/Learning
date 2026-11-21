# Troubleshooting

Work through the matching section. Commands are run in Administrator PowerShell on Windows or via SSH on the Ubuntu VM unless stated.

## A docker.exe / Docker Desktop warning appears

Do not install it. Docker Desktop is unsupported on Windows Server and unnecessary here. The Linux containers run on Docker Engine inside the Ubuntu VM. If you already installed it, uninstall it and reboot before creating the VM.

## DNS: `nslookup lms.geslab.local` fails on a student PC

1. On the Windows Server:
   ```powershell
   Resolve-DnsName lms.geslab.local
   ```
   - If this fails, rerun `windows/Configure-DNS.ps1`.
   - If it returns an IP other than `192.168.10.196`, the A record is wrong — rerun the script (it replaces wrong records).
2. If the server resolves it but students do not, their DNS server is not the Windows Server. Set DHCP option 006 to `WINDOWS_IP`, or configure DNS statically on the clients.
3. Check the Windows Firewall is not blocking DNS (UDP/TCP 53) from the LAN.
4. Clear the student PC's DNS cache: `ipconfig /flushdns`.

### DNS fallback with hosts file

If you cannot change DNS for the students, add one line to `C:\Windows\System32\drivers\etc\hosts` on **each** student PC (requires local admin):

```
192.168.10.196    lms.geslab.local
```

It works, but must be repeated on all 40 PCs and will not update if the IP changes. Prefer DNS.

## Static IP inside Ubuntu (if reservation is impossible)

Find the interface name (`ip -br link` — usually `eth0`), then edit the netplan file:

```bash
sudo nano /etc/netplan/50-cloud-init.yaml
```

Example (adjust gateway and mask to your LAN):

```yaml
network:
  version: 2
  ethernets:
    eth0:
      addresses: [192.168.10.196/24]
      routes:
        - to: default
          via: GATEWAY_IP
      nameservers:
        addresses: [WINDOWS_IP, 8.8.8.8]
```

```bash
sudo netplan try
sudo netplan apply
ip -4 addr
```

Do not use this and a DHCP reservation at the same time.

## Port 80 is already in use on the VM

```bash
sudo ss -tlnp | grep ':80 '
```

If another service owns port 80, stop it (`sudo systemctl stop <service>`) or disable it. Do not change the proxy port — students expect `http://lms.geslab.local` with no port.

## Frontend loads but API calls fail / CORS error

- Confirm `ALLOWED_ORIGINS` in `lms-api/.env.docker` contains `http://lms.geslab.local` exactly, with no trailing slash.
- Confirm `API_BASE_URL=http://lms.geslab.local` in `/opt/learning/.env.docker` and that the frontend image was **rebuilt after setting it**:
  ```bash
  cd /opt/learning && docker compose up -d --build frontend
  ```
- Check the proxy routing:
  ```bash
  curl -i http://127.0.0.1/api/auth/me
  ```
  A `401` means the proxy reached the API correctly.

## API returns 500 / database connection failed

1. Check the DB is healthy:
   ```bash
   cd /opt/learning && docker compose ps
   docker compose logs --tail=100 db
   ```
2. Confirm the DB variable **names** in `lms-api/.env.docker`: `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_HOST=db`, `DB_PORT=3306`. The names `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` are wrong for this application.
3. Confirm the values match `.env.docker`.
4. Recreate the API container:
   ```bash
   docker compose up -d --force-recreate api
   ```

## Uploads 404 or permission errors

```bash
ls -ld /opt/learning/lms-api/uploads /opt/learning/lms-api/logs
sudo chown -R www-data:www-data /opt/learning/lms-api/uploads /opt/learning/lms-api/logs
```

Images are served from `/uploads/...` on the API origin; the proxy routes `/uploads/` to the API. Check with:

```bash
curl -I http://127.0.0.1/uploads/<institution_id>/<category>/<file>
```

## Nothing starts after power on

Check in this exact order:

1. **Physical:** server has power; BIOS *AC Recovery* = Power On.
2. **Hyper-V service:**
   ```powershell
   Get-Service vmms | Select-Object Status
   ```
3. **VM state and auto-start:**
   ```powershell
   Get-VM -Name Learning-Docker | Select-Object State, AutomaticStartAction, AutomaticStartDelay
   ```
   Expected: `Running`, `Start`, `60`.
4. **Docker in Ubuntu:**
   ```bash
   systemctl is-enabled docker
   systemctl is-active docker
   ```
   Both should say `enabled` / `active`.
5. **Containers:**
   ```bash
   cd /opt/learning && docker compose ps
   ```
   All services have `restart: unless-stopped`, so Docker starts them when the daemon starts.
6. **DNS on the students** (see the DNS section above).

## VM will not boot after removing the ISO

In Hyper-V Manager → VM Settings → Firmware, make sure **Hard Drive** is above **DVD Drive** in the boot order. Then remove the DVD drive entirely if it is still present.

## RDS students are slow after deployment

1. Run `windows/Monitor-RDS.ps1` again during peak use.
2. Check the VM is not exceeding its allocation:
   ```powershell
   Get-VM -Name Learning-Docker | Select-Object CPUUsage, MemoryAssigned
   ```
3. Check container limits:
   ```bash
   docker stats --no-stream
   ```
4. If available host RAM stays below 2–3 GB or CPU above 85%, stop the VM until the workload is reduced or RAM is upgraded:
   ```powershell
   Stop-VM -Name Learning-Docker
   ```
   Never use `Stop-VM -TurnOff` for normal shutdowns.

## Email is not appearing in MailHog

MailHog is intentionally kept in the production stack because the school has no internet.

1. Confirm the container is running:
   ```bash
   cd /opt/learning && docker compose ps mailhog
   ```
2. Confirm the API env points at it:
   ```bash
   grep -E '^MAIL_(HOST|PORT)' lms-api/.env.docker
   ```
   Expected:
   ```
   MAIL_HOST=mailhog
   MAIL_PORT=1025
   ```
3. Recreate the API after changing mail settings:
   ```bash
   docker compose up -d --force-recreate api
   ```
4. Open the MailHog UI: `http://192.168.10.196:8025`. Trigger an LMS email (password reset, for example) and it should appear.
5. Check logs:
   ```bash
   docker compose logs --tail=100 mailhog
   docker compose logs --tail=100 api
   ```

To use a real SMTP provider later, change `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` in `lms-api/.env.docker` and recreate the API. MailHog can stay running; it will simply receive nothing.

## Backup script says "External USB backup is not mounted"

- Check the USB drive is plugged in and the Windows share exists.
- Check the mount:
  ```bash
  mountpoint -q /mnt/learning-backups && echo MOUNTED || echo NOT_MOUNTED
  sudo mount -a
  df -h /mnt/learning-backups
  ```
- On Windows, confirm the share:
  ```powershell
  Get-SmbShare -Name 'LearningBackups$'
  ```

## Restore

Never restore without stopping the API first. Use the provided script:

```bash
cd /opt/learning
./restore.sh /mnt/learning-backups/learning_YYYY-MM-DD_HH-MM-SS.sql.gz
```

It stops the API, restores the database, offers to restore the uploads archive, verifies, and starts the API again. Restoring overwrites current data — choose the backup carefully.

## Where to look for errors

| What | Where |
| --- | --- |
| API application errors | `/opt/learning/lms-api/logs/` |
| API container output | `docker compose logs --tail=200 api` |
| Database output | `docker compose logs --tail=200 db` |
| Proxy output | `docker compose logs --tail=200 proxy` |
| Backup failures | `/var/log/learning-backup.log` |
| Windows VM events | Event Viewer → Hyper-V-VMMS |
