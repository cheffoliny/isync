# Sync Control

PHP 8.1+ / WAMP control panel for one-way full MySQL table copies. Required extensions: pdo_mysql, pdo_sqlite, sodium. Designed for MySQL 5.7 → 8 with InnoDB tables and no destination foreign keys.

## Start

1. Run `php bin/admin.php` locally and create the administrator (password: at least 12 characters). The terminal input is visible; do not share or record that terminal.
2. Open `http://localhost/Sync/` and log in.
3. Add source and destination connections. Test connections and list tables.
4. Create a job; destination tables are created or replaced using the source definition. `minute` means every minute; `08:00,20:00` means twice daily, Europe/Sofia.
   Table selection accepts `*` (all source base tables), `sales_docs_2026*` (prefix), or comma-separated combinations such as `products,sales_docs_2026*`. Only `*` is special; `_` and `%` are literal. Patterns are case-sensitive, resolved on every run, and overlapping matches are copied once. Views and internal `__sync_*` helper tables are excluded. All selected tables are rebuilt using source definitions. Unmatched wildcard patterns are skipped; missing exact names fail. A job without any matches records skipped. Target-only tables are left untouched.
5. First use a disposable destination database. Run `php bin/worker.php` and inspect history and destination data.
6. In Windows Task Scheduler create a task running whether the user is logged on or not, every minute, without overlapping instances:
   - Program: `C:\wamp64\bin\php\php8.5.0\php.exe`
   - Arguments: `"C:\wamp64\www\Sync\bin\worker.php"`
   - Start in: `C:\wamp64\www\Sync`

The scheduler is deliberately not installed automatically. Confirm the destination database and a test transfer first. The web panel queues manual requests; a worker must run to process them. Disabled jobs can still be manually requested.

### Schedule field

Use `minute` / `1` for every minute; `2 minutes`, `3 minutes`, `15 minutes` (or just `2`, `3`, `15`) for any positive whole-minute interval. `2 minute`, `5 минути`, and the stored canonical `every:5` format are also accepted. Specific daily hours remain supported: `08:00,12:30,20:00`. Intervals use fixed absolute time buckets, including across hour/day changes; the first run may be immediate and subsequent due boundaries follow that interval. Worker must still be invoked every minute. Missed intervals are consolidated, not replayed. Jobs do not overlap under the existing global lock; a long-running job can delay other schedules. Manual requests bypass the schedule.

## Private storage

Default: `C:\wamp64\sync-private`, outside the web root. Override using the same `SYNC_STORAGE` environment variable for Apache and the scheduled task. Contains SQLite settings/history, encrypted MySQL passwords, encryption key, lock. Back up the database and key together. Restrict NTFS access to administrators, Apache's account and the scheduler's account. Encryption does not protect against an attacker who can read both key and database.

Apache must honor `.htaccess` (`AllowOverride` with authorization and Options enabled). Only index.php is a public PHP entry point. For a non-Apache deployment explicitly block app/ and bin/. Internet access requires HTTPS to protect credentials and cookies.

## Moving to another Windows machine or hosting

Use the panel's configuration export/import section. Export downloads a `.sync` archive encrypted with a separate password of at least 12 characters (PBKDF2-SHA256, random salt, authenticated Sodium secretbox). It includes MySQL credentials and job definitions, without the local encryption key. Keep the archive password separately.

On the destination install the application, create a local administrator, then import the archive. Connections are encrypted with the destination's own key. Import appends connections/jobs in one transaction, remaps connection IDs and leaves imported jobs disabled. Repeated imports create additional copies. Existing records, administrators and execution history are preserved. Test the new connections before enabling jobs. Disable the old installation's schedules before enabling the new one.

For a host-specific private storage directory, copy `app/config.example.php` to `app/config.php` and set an absolute writable path outside the public web root, e.g. `/home/account/sync-private` on Linux or `C:/wamp64/sync-private` on Windows. `SYNC_STORAGE` overrides this setting. Both web and CLI read this same file. The current installation keeps its existing storage path unless explicitly changed. Changing the path does not move existing files automatically.

Hosting needs PHP 8.1+, pdo_mysql, pdo_sqlite, sodium, outbound MySQL connectivity and CLI cron access with a suitable minimum interval. Configure cron to invoke `/usr/bin/php /home/account/public_html/Sync/bin/worker.php` each minute (adjust paths). A host offering only less frequent cron cannot provide a one-minute schedule. Do not expose worker.php as a public HTTP endpoint. Set HTTPS and filesystem access permissions before public use.

## Copy behavior and limits

### History retention

The panel displays the newest 50 runs using the existing INTEGER PRIMARY KEY index (no duplicate ID index is needed). The retention setting defaults to 30 days and is configurable in the authenticated panel from 1 to 3650 days. Each worker checks cleanup before executing jobs, at most once per 24 hours; changing the setting requests cleanup on the next worker run. Finished records older than the cutoff, including successes/errors/skipped runs, are deleted in chunks of 2000 using a partial expression index on finish timestamps. Running and unfinished records are excluded. Retention applies to the local installation and is not part of the configuration archive. No MySQL table data is affected. SQLite reuses freed pages; the database file may not immediately shrink on disk. Plain-text redirected worker logs and trigger recovery files are not included in this history policy.

- One global worker lock prevents all concurrent sync tasks, including overlapping target sets.
- Source tables use one consistent InnoDB snapshot. Destination column definitions are not compared: source SHOW CREATE TABLE is authoritative. Generated columns are computed by MySQL.
- Stage tables always inherit source structure/indexes/charset/defaults/table options. Data loads in batches of up to 800 rows, with commits around 4000 rows, with count verification before one multi-table RENAME. Existing destination data remains until the swap. Destination application writes must not occur: full copy replaces them.
- All selected destination tables, existing or missing, are built from source SHOW CREATE TABLE under staging names, including columns/indexes/defaults/charset/table options, and published in one RENAME. Source foreign keys are unsupported and fail explicitly. Source must be InnoDB. DDL must be compatible with the target MySQL version and SQL mode; incompatible definitions produce a specific creation error. Triggers for selected tables are copied from source SHOW CREATE TRIGGER, preserving bodies and DEFINER. They are created only after loading/switching tables and dropping old tables, so inserts during copying do not fire them. There is a non-atomic interval without triggers; destination application writes must be stopped during synchronization. Source account requires TRIGGER privileges to enumerate/read definitions. Target account requires TRIGGER and privileges needed for the original DEFINER (and binary logging rules); the DEFINER account must exist with suitable permissions. References to explicitly qualified database names inside trigger bodies are preserved, not rewritten. Private triggers-job-*.json recovery files are saved before table publication. Failure after publication reports that data is copied but triggers may be incomplete; inspect and recover before further use. Target-only triggers on replaced tables are removed.
- Source schema replaces existing target schema completely, without ALTER or column comparison. Target-local columns/indexes/options are discarded. Source SQL mode is applied to the target connection after version compatibility adjustment; NO_AUTO_VALUE_ON_ZERO preserves explicit zero IDs. MySQL accounts need SELECT on source, and SELECT, INSERT, CREATE, DROP, ALTER on destination as required for table swapping; metadata must be visible.
- Source rows are streamed into native prepared multi-row inserts (up to 800 rows, capped at 60,000 parameters and a conservative fraction of target max_allowed_packet). Batches share transactions committed at about 4000 rows into the unpublished staging table. Values remain bound parameters. Results include `_performance` with setup/total duration and per-table durations, INSERT batch counts and read/write/commit/verification timings. Measure real runtime before enabling minute schedules; slow jobs delay other jobs under the global worker lock. A single row must fit the packet budget; oversized rows fail explicitly.
- `php bin/benchmark-copy.php TARGET_CONNECTION_ID` compares old per-row inserts with bulk inserts using 200 synthetic rows in a connection-local temporary InnoDB table. It verifies values and removes the temporary table; requires CREATE TEMPORARY TABLES. This measures insert latency, not production full-copy throughput.
- Daily jobs run at the latest due time when the worker next runs; missed slots are consolidated. A failed scheduled slot is recorded and retried at the next scheduled slot, or manually.
- Process termination can leave `__sync_new_*` or `__sync_old_*` tables. Next worker marks interrupted runs as errors; inspect and remove leftover helpers only after checking destination state. A connection failure during RENAME can leave its outcome uncertain; inspect the target before retrying.
- No live MySQL transfer has been validated until real connections are supplied. Do not point an untested job at production.
