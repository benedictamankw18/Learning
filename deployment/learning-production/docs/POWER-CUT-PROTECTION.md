# Power-cut protection (no UPS — best effort)

Your server is in Ghana, where light can go out without warning, and you have no UPS. This document explains exactly what the deployment does to reduce risk, what it cannot do, and what to do afterwards.

## What is actually protected

### 1. Automatic return when power comes back

**BIOS/UEFI setting (do this on the physical server):**

- Restart the server and enter BIOS/UEFI setup (usually `Del`, `F2`, `F10`, or `Esc` during boot).
- Find a setting named one of:
  - **Restore on AC Power Loss**
  - **AC Recovery**
  - **Power on after power failure**
  - **After Power Failure**
- Set it to **Power On** (not "Last State" and not "Stay Off").
- Save and exit.

Without this, after a power cut the server stays off until someone physically presses the power button — which defeats the goal of automatic recovery.

### 2. Graceful shutdown for planned maintenance

- You always use **normal Windows Shut Down**.
- The VM's `AutomaticStopAction` is `ShutDown`, so Hyper-V asks Ubuntu to shut down cleanly. Ubuntu's systemd stops Docker, and Docker stops MariaDB gracefully.
- Verify:

```powershell
Get-VM -Name Learning-Docker |
  Select-Object AutomaticStartAction, AutomaticStartDelay, AutomaticStopAction
```

Expected: `Start`, `60`, `ShutDown`.

### 3. Database crash-safety

`ubuntu/maria-production.cnf` sets:

```ini
innodb_flush_log_at_trx_commit=1
innodb_doublewrite=ON
innodb_flush_method=O_DIRECT
sync_binlog=1
```

These mean MariaDB writes its transaction log to disk before reporting a commit, keeps a double-write buffer against torn pages, and flushes synchronously. A sudden power loss should therefore cause **data loss of in-flight transactions at most**, not table corruption.

Ubuntu's default ext4 filesystem is journaled, which also helps.

### 4. Frequent backups to a separate device

`backup.sh` runs:

- every 2 hours, and
- a final time at 16:30 on school days (adjust in the runbook).

Backups go to the external USB drive over a restricted SMB share, not to the VM's own disk. Worst case after a power cut is roughly two hours of lost work, and you have a verified SHA-256 checksum for each backup.

## What software CANNOT protect

Be honest about this:

- If electricity disappears while MariaDB is writing, **no script can make that a clean shutdown**. The settings above reduce the chance of corruption; they do not eliminate it.
- A wall-switch cut or holding the power button is equivalent to pulling the plug.
- Consumer SSDs can lose data held in their write cache during sudden power loss.
- **A UPS is the only real fix.** A small 650–1000 VA UPS with a USB connection to the server lets Windows receive a "battery low" signal and shut down gracefully, and carries the server through short cuts. If you can buy one later, it is the single best upgrade for this setup. Until then, rely on the measures above.

## After a power cut — what to do when you return

1. Check the server powered on by itself. If it did not, the BIOS *AC Recovery* setting from section 1 was not saved or is named differently — fix it.
2. Check the VM is running:

```powershell
Get-VM -Name Learning-Docker | Select-Object Name, State, CPUUsage, MemoryAssigned
```

3. If the VM is off, start it (this should not normally be needed):

```powershell
Start-VM -Name Learning-Docker
```

4. Wait a few minutes, then on the Ubuntu VM:

```bash
systemctl is-active docker
cd /opt/learning && docker compose ps
./check-health.sh
```

5. Check the database survived:

```bash
docker compose exec db sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CHECK TABLE lms.users;"'
```

(Replace `lms.users` with another table if needed. `CHECK TABLE` should report `status = OK`.)

6. If the API is up but data looks wrong, list the newest backups and restore the last good one:

```bash
ls -lt /mnt/learning-backups/ | head
cd /opt/learning && ./restore.sh /mnt/learning-backups/<chosen-file>.sql.gz
```

7. Verify from a student PC that `http://lms.geslab.local` loads and login works.

8. Record the incident: date, time, whether the server restarted by itself, and whether a restore was needed. This tells you whether the BIOS and backup settings are doing their job.

## Never do these

- Do not hold the power button to shut down unless the machine is completely frozen and there is no alternative.
- Do not switch off at the wall while students are using the LMS.
- Do not run `docker compose down -v` — it deletes the database volume.
- Do not disable the USB backup share because it seems unused; it is your recovery path.
