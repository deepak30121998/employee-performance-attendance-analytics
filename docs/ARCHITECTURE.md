# Architecture & Design Rationale

This document explains the choices behind the parts of the system that aren't obvious from
the code alone: timezone handling, concurrency guarantees, the CSV import pipeline at scale,
index design (with real `EXPLAIN` evidence), notification idempotency, and how the design
would evolve at 10M+ rows.

## Timezone strategy

`APP_TIMEZONE=Asia/Kolkata` (`.env`). Every timestamp — `check_in_at`, `check_out_at`,
`created_at`, etc. — is stored and read in this single organizational timezone, not UTC.
The MySQL session timezone is pinned to the same offset (`config/database.php`,
`DB_TIMEZONE=+05:30`), so TIMESTAMP columns round-trip identically for any client, including
raw SQL/BI tools that don't go through Laravel.

This is a deliberate simplification for a **single-tenant, single-office** HR system: there is
no cross-timezone requirement in the spec, and storing wall-clock local time avoids a whole
class of "was that 6pm IST or 6pm UTC" bugs in a system whose only real consumers are HR staff
looking at a dashboard in one office. `Attendance.date` (the calendar day) is always derived
from `check_in_at` in this same timezone, so a check-in at 12:30am IST correctly lands on
*that* calendar day even though the server's own clock is UTC. If this ever needed to support
multiple offices in different timezones, the fix is: keep storage in UTC, add a
`timezone`/`site` concept to `Department`, and convert to that department's timezone only at
the point `date` is derived and at display time — everything else in the codebase already
treats `date` as the single source of truth for "which day" a record belongs to, so that
change is localized to one place (`AttendanceService`).

## Concurrent check-in — the actual guarantee

Two check-in requests arriving at the same instant for the same employee are resolved by a
**database-level unique index**, not application logic:

```php
$table->unique(['employee_id', 'date']);          // Modules/Attendance/.../create_attendances_table.php
```

`AttendanceService::checkIn()` first does an application-level existence check (fast path,
turns the common "you already checked in" case into a clean `409` without ever hitting the
unique constraint). But that check is **not** the guarantee — two requests can both pass it in
the same instant, both proceed to `INSERT`, and MySQL's index enforces that only one commits.
The loser's `QueryException` (SQLSTATE `23000`) is caught and translated to the same `409`
response the fast path returns, so the caller can't tell which path they hit — only one ever
sees success.

This is proven with a real race, not just reasoning about it:
`Modules/Attendance/tests/Feature/AttendanceConcurrencyTest.php` uses `pcntl_fork()` to run two
literal OS processes, each with its own DB connection (`DB::purge()` after fork — a forked
child inherits and would otherwise corrupt the parent's connection), both calling
`AttendanceService::checkIn()` for the same employee at the same time. The test asserts exactly
one succeeds, the other gets `AttendanceConflictException`, and exactly one row exists
afterward. It deliberately doesn't use `RefreshDatabase` (a forked child would inherit and
corrupt the parent's open transaction) and self-skips under a parallel test runner.

Check-out uses `lockForUpdate()` inside `DB::transaction()` instead of a unique index, because
the invariant there is different: not "at most one row", but "check-out must not race against
a concurrent check-out for the *same* row". The row lock makes the second concurrent
check-out wait for the first to commit, then re-checks `isActive()` under the lock before
computing `working_minutes` — so it can never overwrite an already-completed session.

## Duplicate attendance / performance — three distinct mechanisms

The spec asks about "duplicate attendance" in three different contexts, and each is handled
differently because they're genuinely different problems:

1. **Same employee, same day, two manual check-ins** → the unique index above.
2. **Same employee, same month, two `POST /api/performance`** → `unique(employee_id, month)`
   on `performance_scores`, checked at the application level first
   (`PerformanceService::record()`), same "index is the real guarantee" pattern: if two
   requests race past the pre-check, the losing insert's duplicate-key violation (MySQL
   errno 1062) is caught and translated to the same `409`, never a raw 500. Proven by
   `PerformanceConcurrencyTest` with two forked processes. Duplicates are **rejected**, not
   upserted — correcting a score is an explicit decision, not something a second POST should
   do silently.
3. **CSV import writes a row that collides with an existing one** → decided by *who* wrote the
   existing row (`attendances.source`): if the existing row came from a **prior run of this
   same import** (`source = 'import'`), it's a safe no-op — the import is being retried or
   re-processed and this is exactly what should happen. If the existing row is a **real manual
   check-in** (`source = 'manual'`), the import must never silently overwrite it — that's
   recorded as a `duplicate attendance` row error instead. See the source check inside
   `ProcessAttendanceImportJob::processChunk()`.

## Duplicate whole-file import

Uploading the identical file twice is rejected **before it's even queued**:
`ImportService::upload()` computes a `sha256` of the file content and looks it up against
`import_batches.checksum` (a unique column). A second upload of byte-identical content gets an
immediate `409` naming the existing batch — it never reaches the queue, so there's no
processing cost to reject it.

This is orthogonal to row-level idempotency (mechanism 3 above): the checksum guard stops a
*new upload* of the same file; the row-level source check is what makes *retrying the same
batch's job* safe (see next section).

## 500,000+ row imports

`ProcessAttendanceImportJob` is designed around three constraints: bounded memory, a request
that returns immediately, and safety under retry.

- **Streaming, not loading**: the file is read with `fopen()`/`fgetcsv()`, one line at a time.
  The full file is never held in memory — memory use is bounded by one row plus one chunk
  buffer, regardless of whether the file has 5,000 or 5,000,000 rows.
- **Chunking**: rows are batched into groups of 500 (`CHUNK_SIZE`). Each chunk is processed and
  committed inside its own `DB::transaction()` — the counters
  (`processed_rows`, `imported_attendance_count`, etc.) update after every chunk, so an admin
  polling `GET /api/import/{id}` sees live progress instead of an opaque `processing` status
  for the whole run.
- **Batch reads and batch writes, not N+1**: within a chunk, employee emails are resolved with
  **one** `whereIn('email', ...)` query for the whole chunk (500 rows → 1 query, not 500).
  Existing-row conflict checks are the same shape — **one** `whereIn('employee_id', ...)
  ->whereIn('date', ...)` pre-fetch per chunk for attendance, and one for performance scores
  (`ProcessAttendanceImportJob::existingAttendanceKeys()` /`existingPerformanceKeys()`) — never
  a per-row existence query. Every row's outcome (new / already-imported / conflict) is decided
  against that in-memory lookup, and every genuinely-new row across the whole chunk is written
  with **one** multi-row `INSERT` (`DB::table('attendances')->insert($newAttendanceRows)`), not
  one `INSERT` per row. A chunk of 500 rows costs at most 4 queries total (2 pre-fetches, up to
  2 bulk inserts) plus the row-error insert, regardless of how many of those 500 rows actually
  write data.

  One correctness subtlety this creates: two rows *within the same chunk* for the same
  `(employee_id, date)` (e.g. an accidental duplicate line in the CSV itself) must not both
  land in the bulk-insert array — a duplicate key inside one multi-row `INSERT` statement would
  throw and roll back the *entire chunk's transaction*, valid rows included. The in-memory
  lookup map is updated the instant a row is accepted for insertion (before moving to the next
  row), so the second occurrence sees it as "already claimed" and is caught as a normal
  duplicate — never reaching the `INSERT`.
- **Not blocking the request**: `POST /api/import` only validates the upload, stores the file,
  creates the `ImportBatch` row, and dispatches `ProcessAttendanceImportJob` — it returns `202`
  immediately. All row processing happens on a queue worker.
- **Retry safety / idempotency, two layers deep**:
  1. *Efficiency*: `last_processed_row` is updated after every committed chunk. If the job is
     retried (Laravel's own `$tries`/`backoff`, or a worker crash), it skips re-reading rows
     already committed in an earlier attempt.
     `ImportTest::test_a_retried_job_resumes_from_last_processed_row_instead_of_reparsing_earlier_rows`
     proves the skip itself, not just its effect: it plants a row that would fail with
     `employee not found` if it were ever parsed, sets `last_processed_row` past it, and asserts
     no such row error is ever recorded.
  2. *Correctness, independent of layer 1*: even if the resume cursor didn't exist at all —
     i.e. the entire file were reprocessed from row one — every row write still checks for an
     existing row first (the `source`-aware check described above). Re-running the whole job
     from scratch is provably safe, not just efficient-when-resumed.
     `Modules/Import/tests/Feature/ImportTest.php::test_retrying_a_full_reprocess_does_not_duplicate_rows`
     proves this directly: it resets `last_processed_row` to 0 and re-invokes `handle()` on an
     already-completed batch, then asserts the attendance/performance row counts are unchanged.
- **Timeout / retry configuration**: `$timeout = 1800` (30 min — generous for I/O-bound CSV
  parsing even on a slow disk), `$tries = 3`, `$backoff = [10, 30, 90]` seconds.

**Real evidence, not just architecture**: `Modules/Import/tests/Feature/LargeImportTest.php`
runs a genuine 20,000-row file by default (200 employees × 100 days) through the actual
queued job and measures wall-clock time and peak memory delta, asserting memory stays under a
fixed 64MB ceiling regardless of row count (streaming means it should never scale with row
count — a regression back to loading the whole file would blow past this on a much smaller
file, not just a large one).

The row count is configurable, so the literal spec figure has been run and measured:

```
LARGE_IMPORT_ROWS=500000 php artisan test --filter=LargeImportTest

[LargeImportTest] 500,000 rows in 37.69s (13,267 rows/sec), peak extra memory 4.0MB
```

Comfortably inside the job's 30-minute `$timeout`, memory flat as designed, and irrelevant to
the HTTP request either way since processing happens entirely on the queue worker. CI runs
keep the 20k default — same assertions, a fraction of the wall-clock.

**Further scaling beyond this implementation**: at genuinely extreme volume (tens of millions
of rows in one file), the next lever is chunk size itself — 500 balances transaction overhead
against per-chunk memory and lock duration; a much larger chunk (e.g. 5,000) would reduce query
round trips further at the cost of a longer-held transaction and a bigger in-memory pre-fetch
map per chunk. Not tuned further here because 500 already keeps every chunk's transaction well
under a second (see the throughput figure below) at the volumes this spec describes.

## What happens if a notification job runs twice?

Both queued notifications (`NotifyEmployeeOfPerformanceScore`, `NotifyManagerOfAbsence`) guard
against this explicitly, because Laravel's own database-notifications channel has no built-in
dedup — calling `->notify()` twice just inserts two rows.

Two layers, because retries and races are different failure modes:

- **`ShouldBeUnique` on both jobs** (`uniqueId()` = the business key) — stops two workers from
  even picking up the same logical notification at the same time. The check-then-insert query
  below is not concurrency-safe on its own; the unique-job lock is what closes that window.
- **A `DatabaseNotification` existence check inside `handle()`** — before calling `->notify()`,
  query for an existing row of the same notification type carrying the same business key
  (`performance_score_id` for the score notification; `employee_id` + `date` for the absence
  notification). This covers the sequential case: a retry after a crash mid-delivery, or a
  re-dispatch after the unique lock has already expired.

This is proven, not just asserted: `PerformanceScoreTest` and
`MarkAbsenteesCommandTest::test_running_twice_for_the_same_day...` both invoke the real
(non-faked) notification path twice and assert exactly one row lands.

## Indexes — reasoning, and real `EXPLAIN` evidence

Every index on `attendances` earns its place from an actual query pattern, not speculatively:

| Index | Serves |
|---|---|
| `unique(employee_id, date)` | The concurrency guarantee above; also the natural PK lookup for "this employee's record for today". |
| `(date, status, employee_id)` | Analytics: `GROUP BY employee_id` over a date range, reading `status` — a **covering** index (all three columns the query touches are in the index), so MySQL never visits the base row. |
| `(department_id, date)` | Manager's attendance listing: "this department, ordered by date". See the before/after below — this index was added *after* `EXPLAIN` caught a real full-table-scan. |

**`department_id` on `attendances` is denormalized from `users.department_id`** at write time
(set once, in `AttendanceService::checkIn()`, `ProcessAttendanceImportJob`, and
`MarkAbsenteesCommand`) rather than joined/derived live. Two reasons: it makes the manager
listing query a single range scan instead of a join or an `IN (...)` list (see below), and it's
**more historically correct** — if an employee later transfers departments, their old
attendance rows stay attributed to the department they were actually in at the time, which a
live join would get wrong.

### Before/after: the manager listing query

Seeded 35,600 real rows (200 employees × ~178 working days) and ran `EXPLAIN` on "list this
department's attendance, newest first" — exactly what `GET /api/attendance` runs for a manager,
and structurally identical to `paginateForRole()`'s generated SQL.

**Before** (scoping via `WHERE employee_id IN (<200 ids>) ORDER BY date DESC`, resolved from a
separate `users` lookup — the natural way to write this without a denormalized column):

```
type: ALL   rows: 35600   Extra: Using where; Using filesort
```

A full table scan. With 200 ids in the `IN` list, MySQL's optimizer decided a filtered scan +
sort was cheaper than 200 index dives — this gets *worse*, not better, as the department or the
table grows.

**After** (scoping via `WHERE department_id = ?`, the actual current code):

```
type: ref   key: attendances_department_id_date_index   rows: 17800   Extra: Using filesort
```

An index range scan on `(department_id, date)` — `rows` examined drops to exactly this
department's rows, and cost now scales with *department size*, not company-wide table size.
(The remaining `Using filesort` is `ORDER BY date DESC, id ASC` — MySQL doesn't provide a free
sort here because of the `id ASC` tiebreaker; per-page cost is bounded by
`cursorPaginate()`'s page size regardless.)

The `presentDaysByEmployee()` aggregate (used by every Analytics dashboard) was checked the
same way:

```
type: range   key: attendances_date_status_employee_id_index   rows: 4600   Extra: Using where; Using index; Using temporary
```

`Using index` confirms it's a covering index scan — no row lookups at all for this query.

## If `attendances` had 10 million rows and the dashboard took several seconds

In order of what to reach for first:

1. **Confirm it's actually the query, not something else, with `EXPLAIN` first** — the section
   above is the template: seed representative volume, `EXPLAIN` the actual generated SQL (not
   a hand-simplified version of it), read `type`/`key`/`rows`/`Extra`, fix the specific gap.
   `type: ALL` on a table this size is the signal to act on, not a general "it feels slow".
2. **Composite indexes leading with the column the query actually filters on**, exactly as
   above — a single-column index on `status` alone is useless if the query's real filter is
   `department_id` (MySQL would still scan the whole `status='present'` slice across every
   department). Every index here leads with the column the WHERE clause anchors on.
3. **Cursor pagination everywhere a list can grow unbounded** — already the case:
   `paginateForRole()` uses `cursorPaginate()`, not `paginate()`/`OFFSET`, on both the
   Attendance and Performance listing endpoints. `OFFSET 500000` still has to walk and discard
   500,000 rows even with a perfect index; keyset pagination (`WHERE (date, id) < (?, ?)`) does
   not.
4. **Push aggregation into SQL, never into PHP** — every Analytics number
   (`presentDaysTotal`, `employeesBelowAttendanceThreshold`, `averageScore`, `topScorers`) is a
   single `GROUP BY`/`AVG`/`HAVING`/`ORDER BY ... LIMIT` query, not "fetch rows, loop in PHP".
   The below-threshold list in particular is a `LEFT JOIN users → attendances` with
   `HAVING COUNT(*) * 100 < threshold * expected_days`, so employees with *zero* attendance
   rows are still flagged and the department/role scoping stays in the database — no
   pre-plucked ID list is ever bound into a `whereIn`.
5. **Cache the aggregate, not just the query** — `AnalyticsService` wraps every dashboard in a
   30-minute cache via `AnalyticsCache`. At 10M rows even a well-indexed aggregate still costs
   real time; the fix for a *dashboard* (as opposed to a live check-in) is to not run it on
   every request at all. Invalidation is version-key based rather than `Cache::tags()` —
   every dashboard key carries an `analytics:v{N}:` prefix and invalidating means bumping `N`,
   which works on any cache store (tags throw on the `file`/`database` stores). An
   `Attendance`/`PerformanceScore` Eloquent save bumps the version via a model observer; the
   CSV import's bulk writes bypass Eloquent entirely (`DB::table()->insert()`), so the import
   job bumps it explicitly once at the end of the run — the one path that would otherwise
   silently serve stale dashboards for up to the 30-minute TTL.
6. **Archiving / partitioning** — genuinely warranted once a single tenant's attendance history
   spans years and the *working set* (this month, last month) is a small fraction of the total.
   MySQL 8 supports `PARTITION BY RANGE` on `date` (e.g. one partition per year); a query
   scoped to a recent range then only touches the relevant partition(s), and older partitions
   can be archived to cold storage independently. Not implemented here — no table in this
   system is within an order of magnitude of needing it yet, and adding partitioning to a live
   table later is a heavier migration than adding one now to an empty one, so it's listed here
   as the next step rather than spec'd prematurely.

## Working days and holidays

Attendance percentages divide by "expected working days": Mon-Fri minus the `holidays` table
(`WorkingDaysCalculator`, fed by `HolidayRepositoryInterface` from the Attendance module).
The absentee scheduler skips weekends and holidays for the same reason - nobody should be
marked absent on Diwali. Holidays are seeded from `HolidaySeeder` and can be managed directly
in the table; there is deliberately no CRUD endpoint for them, they change a few times a year.

## Scheduler: idempotent by construction

`attendance:mark-absentees` is designed so a same-day re-run (a cron misfire, a manual re-run
to demo it, an operator running it twice) is a no-op for anyone already recorded: it computes
`employees not yet in `attendances` for this date` up front, and only that set gets a new row
and a notification. `MarkAbsenteesCommandTest::test_running_twice_for_the_same_day...` runs the
command twice and asserts one row, one notification.
