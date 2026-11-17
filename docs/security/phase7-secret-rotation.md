# Phase 7: Secret Rotation and History Purge Plan

Status: **plan only**. No credentials have been rotated and no Git history has
been rewritten.

## Why this is required

The repository history contains tracked environment files and placeholder-like
credentials that must be treated as compromised:

- `.env`
- `lms-api/.env`
- `lms-frontend/.env`
- `changeme_pass` and `JWT_SECRET=` values in commits `cd8d8c3`, `7c6e45a`,
  and `bca6191`

A secret that has ever been committed is compromised, even if it was later
removed from the working tree. Removing a file in a newer commit does not
remove it from earlier commits, clones, forks, caches, or CI logs.

## Scope

Rotate every credential that may have appeared in a tracked environment file:

- `JWT_SECRET` (rotating this invalidates all access and refresh tokens)
- Database user passwords, including the application user and any root/admin
  account used by Docker Compose
- SMTP/email passwords
- Any API keys, webhook secrets, or third-party tokens copied into `.env` files
- Any institution-specific credentials that were stored outside `.env`

The working tree is already protected against new accidental commits:

- Only `.env.example` and `.env.docker.example` files are tracked.
- `lms-api/tests/StaticChecksTest.php` fails if a real `.env` file is tracked
  or if credential-looking values appear in tracked files.
- `lms-api/config/jwt.php` fails closed unless `JWT_SECRET` is at least 32
  characters and is not a known placeholder.

## Rotation procedure

Perform rotation before any history rewrite. If history is purged first, the
old credentials may still be usable until they are rotated.

### 1. Prepare

1. Identify every deployment, developer machine, CI/CD secret store, and
   backup that uses the current values.
2. Take a database backup.
3. Schedule a maintenance window. Rotating `JWT_SECRET` logs every user out.
4. Have a rollback path ready: keep the old values in a secure secret manager
   until the new deployment is verified.

### 2. Generate new values

Generate high-entropy values locally, never in a tracked file:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use a distinct value for each secret. Do not reuse the JWT secret as a database
password or mail password.

### 3. Rotate the database password

Preferred order:

1. Create a new database user or set a new password for the application user.
2. Update the deployment secret store with the new value.
3. Restart the API and verify database connectivity.
4. Remove or disable the old credential.

For MySQL/MariaDB, the exact command depends on the account model:

```sql
ALTER USER 'lms_user'@'%' IDENTIFIED BY '<new-db-password>';
FLUSH PRIVILEGES;
```

If Docker Compose is used, update `DB_PASS`, `DB_ROOT_PASSWORD`, and any
`MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` values together so the container does
not restart with mismatched credentials.

### 4. Rotate `JWT_SECRET`

1. Set a new value of at least 32 characters in the deployment secret store.
2. Deploy the API with the new value.
3. Invalidate existing refresh tokens. Rotating the signing secret already
   makes old JWTs invalid, but the stored refresh-token rows should also be
   revoked or removed:

```sql
UPDATE refresh_tokens SET revoked_at = NOW() WHERE revoked_at IS NULL;
```

4. Verify that old access and refresh tokens are rejected and that users can
   log in again.

### 5. Rotate mail and third-party credentials

1. Rotate the SMTP password or app password.
2. Update the deployment secret store.
3. Send a test email through the application.
4. Revoke old API keys and webhook secrets at the provider.
5. Update the provider callback URLs only if the domain changed.

### 6. Verify

- Log in with a normal user, an institution admin, and a super admin.
- Confirm that refresh-token rotation still works.
- Confirm that file uploads and downloads work.
- Confirm that email notifications are delivered.
- Check application logs for authentication or database errors.
- Confirm that the old credentials no longer work.

## History purge procedure

Only run this after rotation is complete and approved by the repository owner.
History rewriting is destructive: every collaborator must re-clone, and open
pull requests may need to be recreated.

### 1. Back up the repository

```bash
git clone --mirror <repository-url> lms-backup.git
```

Keep the backup offline or in a restricted location until the purge is
verified. The backup still contains the old secrets, so protect it accordingly.

### 2. Install and use `git filter-repo`

`git filter-repo` is the recommended tool. It is not part of Git itself.

```bash
git clone --mirror <repository-url> lms-purge.git
cd lms-purge.git
git filter-repo --path .env --path lms-api/.env --path lms-frontend/.env --invert-paths
```

If specific secret values also appeared in other files, create a replacement
file with one `literal==>replacement` rule per line and run:

```bash
git filter-repo --replace-text replacements.txt
```

Keep `replacements.txt` outside the repository and delete it securely after
the purge.

### 3. Verify the rewrite

```bash
git log --all --oneline -- .env lms-api/.env lms-frontend/.env
git log --all --oneline -S 'changeme_pass'
git log --all --oneline -S 'JWT_SECRET='
```

The first command should return nothing. The `-S` commands should no longer
find the old secret values. Also scan the rewritten history for private keys
and provider token patterns before publishing.

### 4. Publish the rewritten history

```bash
git push --force --all
git push --force --tags
```

Coordinate with the repository owner before force-pushing. If the repository
is hosted on GitHub, GitLab, or another platform, ask the provider to expire
cached views of old commits. Forks and existing clones will still contain the
old history until they are deleted or re-cloned.

### 5. Notify collaborators

Every collaborator must:

1. Delete their local clone (or re-clone from the rewritten remote).
2. Delete old branches and tags that point at pre-rewrite commits.
3. Recreate open pull requests against the rewritten history.
4. Rotate any credentials that were stored only on their machine.

## Rollback

If the rewrite breaks the repository, restore from `lms-backup.git` and
re-publish. Do not restore the old secrets to production. Rotation is
independent of the history rewrite and should not be rolled back.

## Checklist

- [ ] All credentials identified and inventoried
- [ ] Database backup completed
- [ ] New database password applied and verified
- [ ] New `JWT_SECRET` deployed and old tokens revoked
- [ ] SMTP and third-party credentials rotated
- [ ] Old credentials confirmed unusable
- [ ] Repository mirror backed up
- [ ] History rewritten and verified
- [ ] Force-push approved and completed
- [ ] Collaborators notified and re-cloned
- [ ] Hosting-provider cache purge requested
- [ ] Post-purge security scan completed

## Current status

- Working tree: only `.env.example` and `.env.docker.example` are tracked.
- Local development database: `refresh_tokens` table created with
  `CREATE TABLE IF NOT EXISTS`; no production schema change was made.
- History: **not rewritten**.
- Credentials: **not rotated**. They must be treated as compromised until
  rotated externally.
