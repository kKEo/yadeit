# Backup and restore

What is backed up, how to get it off the machine, and how to restore it. A
backup you have never restored is a hope, not a backup: practise the restore
once before you need it.

## What is backed up

The `backup` service (`docker/prod/backup.sh`) runs every
`BACKUP_INTERVAL_HOURS` (24) and keeps `BACKUP_KEEP_DAYS` (14) in the
`backups` volume:

| File | Contents |
|---|---|
| `db-<UTC time>.dump` | The whole database, `pg_dump` custom format |
| `files-<UTC time>.tar.gz` | `storage/` without caches and logs: uploads and the OAuth signing keys |
| `last-success` | Touched after each complete backup; the health report reads its age |

Files are written under a `.part` name and renamed when complete, so a
half-written file is never taken for a backup.

**Not backed up:** `.env.production`. Without its `APP_KEY`, workspace AI keys
and webhook secrets in the dump cannot be decrypted. Keep a copy in a password
manager or secret store.

## Commands

```bash
dc exec backup /bin/sh /backup.sh once     # a backup now
dc exec backup /bin/sh /backup.sh list     # what is there
dc logs backup                             # "backup <time> done: 12M database, 340M files"
```

Take a backup by hand before every upgrade that mentions a migration.

## Copy it off the machine

**The backups are on the same disk as the data.** If the machine is lost, so
are they. Copy the volume elsewhere at least daily, for example with `rclone`
to object storage:

```bash
# On the host, from cron, an hour after the backup runs
docker run --rm -v yadeit_backups:/backups:ro -v ~/.config/rclone:/config/rclone:ro \
  rclone/rclone sync /backups remote:yadeit-backups --exclude '*.part'
```

The volume name is `<project>_backups`; the project in `compose.prod.yaml` is
`yadeit`. Alert when the newest file on the remote is older than a day.
Encrypt the remote (rclone `crypt`, or bucket encryption): the dump contains
every workspace's content and every email address.

## Restore

Restoring **replaces everything** in the database with the dump.

```bash
# 1. Stop what writes to the database
dc stop worker scheduler

# 2. Restore the database, and optionally the files
dc exec backup /bin/sh /backup.sh restore \
  /backups/db-20261116-000000.dump /backups/files-20261116-000000.tar.gz

# 3. Start again
dc restart app worker scheduler
dc exec app php artisan app:health
```

`pg_restore` runs with `--clean --if-exists --no-owner --no-privileges`, so
it works into an existing database. A dump from an older release is migrated
forward when `app` restarts.

### Onto a new machine

1. Install as in [Deploy with Docker Compose](../install/docker.md), with the
   **same** `.env.production` (above all `APP_KEY` and `DB_*`).
2. `dc up -d db backup` (only these two).
3. Copy the backup files into the `backups` volume:
   `docker cp db-….dump "$(dc ps -q backup)":/backups/`.
4. Run the restore command above, then `dc up -d`.
5. Sign in, open an item with an attachment, and check the health report.

## Restore drill

Once a quarter, on a spare machine:

- [ ] restore yesterday's off-site copy following "Onto a new machine";
- [ ] sign in as an operator; the health report is `ok`;
- [ ] open a recent item and download an attachment;
- [ ] write down how long it took. That is your real recovery time.
