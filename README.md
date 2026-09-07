# Employee Performance & Attendance Analytics

Laravel backend for employee attendance, monthly performance scores, bulk CSV import,
analytics dashboards and CSV reports. Built as modules (`nwidart/laravel-modules`) with
Sanctum auth and three roles: admin, manager, employee.

More docs:

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) - timezone strategy, concurrency handling, how 500k+ row imports work, indexes/EXPLAIN, scaling notes
- [docs/API.md](docs/API.md) - request/response examples for every endpoint
- [docs/ER-DIAGRAM.md](docs/ER-DIAGRAM.md) - schema
- [docs/postman_collection.json](docs/postman_collection.json) - Postman collection

## Stack

PHP 8.3, Laravel 11, MySQL 8, Redis (queue + cache), Sanctum, PHPUnit

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set your DB and Redis details in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=employee_performance_attendance
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Create the database, then:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

Seeded logins (password is `password` for all):

| Role | Email |
|---|---|
| admin | admin@example.com |
| manager | manager@example.com |
| employee | employee@example.com |

The seeder also adds 4 departments, a manager + 5 employees per department, and a couple of
months of attendance and performance data, so the dashboards aren't empty on first run.
Attendance/performance demo data is skipped if those tables already have rows.

## Queue worker

CSV imports and notifications run on the queue, so keep a worker running:

```bash
php artisan queue:work redis --tries=3
```

Without it, `POST /api/import` still returns 202 but the batch stays `pending`.

## Scheduler / cron

`attendance:mark-absentees` runs daily at 23:55 - it marks no-shows absent, notifies their
managers and saves a summary row in `daily_attendance_summaries`. Add the standard cron entry
on the server:

```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Run it manually for any date if needed:

```bash
php artisan attendance:mark-absentees 2026-08-03
```

## Tests

```bash
php artisan test
```

Tests use a separate `employee_performance_attendance_test` MySQL database (see
`phpunit.xml`). The two concurrency tests fork real OS processes (`pcntl_fork`) to prove the
duplicate check-in / duplicate score guarantees; they skip themselves under `--parallel`.

## API

Full examples in [docs/API.md](docs/API.md).

| Method | Endpoint | Access |
|---|---|---|
| POST | `/api/login` | public |
| POST | `/api/logout` | authenticated |
| POST | `/api/employees` | admin |
| GET | `/api/employees` | admin (all), manager (own dept) |
| GET | `/api/employees/{id}` | admin; manager (own dept); employee (self) |
| PUT/DELETE | `/api/employees/{id}` | admin; manager (own dept, limited fields) |
| GET | `/api/profile` | authenticated |
| POST | `/api/attendance/check-in` | authenticated |
| POST | `/api/attendance/check-out` | authenticated |
| GET | `/api/attendance` | role-scoped |
| POST | `/api/performance` | manager (own dept) |
| GET | `/api/performance` | role-scoped |
| POST | `/api/import` | admin |
| GET | `/api/import`, `/api/import/{id}` | admin |
| GET | `/api/analytics?month=Y-m` | role-scoped dashboard |
| GET | `/api/reports/attendance?from=&to=` | admin, CSV |
| GET | `/api/reports/performance?month=Y-m` | admin, CSV |

Auth is a Sanctum bearer token from `/api/login`. Everything responds in JSON.

Note for big imports: `POST /api/import` accepts up to 100MB, raise `upload_max_filesize`
and `post_max_size` in `php.ini` to match.
