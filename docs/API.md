# API Reference

Base URL: `http://localhost:8000/api` (or whatever `APP_URL` you run with).

All responses are JSON regardless of the request's `Accept` header. All routes except
`/login` require `Authorization: Bearer <token>` (obtained from `/login`).

## Auth

### `POST /login`

```json
// Request
{ "email": "admin@example.com", "password": "password" }
```
```json
// 200
{
  "user": { "id": 1, "name": "Admin", "email": "admin@example.com", "role": "admin", "department_id": null, "department": null, "designation": null, "created_at": "..." },
  "token": "1|abcdef123456..."
}
```
```json
// 422 — wrong credentials
{ "message": "These credentials do not match our records.", "errors": { "email": ["These credentials do not match our records."] } }
```

### `POST /logout`
Revokes the bearer token used on the request. `200 { "message": "Logged out." }`.

### `GET /profile`
Returns the caller's own record (same shape as `login`'s `user`).

---

## Employees (`User` module)

### `POST /employees` — admin only

```json
// Request
{
  "name": "New Hire",
  "email": "new.hire@example.com",
  "password": "password123",
  "role": "employee",
  "department_id": 1,
  "designation": "Software Engineer"
}
```
`201` with the created employee. `403` for non-admins.

### `GET /employees`
Role-scoped: admin sees everyone; manager sees their own department; employee sees only
themselves (in practice, use `/profile` for that). Cursor-paginated —
`{ "data": [...], "links": { "next": "...?cursor=..." }, "meta": {...} }`.

Query params (admin only): `?department_id=`.

### `GET /employees/{id}`
Admin can view anyone; a manager can view employees in their own department; an employee can
only view themselves. `403` otherwise — changing the id in the URL to someone from another
department gets rejected by the policy.

### `PUT /employees/{id}`
Admin may update any field, including `role`/`department_id`. A manager may update an
employee in their own department, but `role` and `department_id` are silently stripped from
the update — a manager cannot escalate a role or move someone out of their oversight.
`403` if the target isn't in the manager's department.

### `DELETE /employees/{id}` — admin only
Soft-deletes. `200 { "message": "Employee removed." }`.

---

## Attendance

### `POST /attendance/check-in`
No body. `201` with the new attendance row. `409` if already checked in today or if
attendance for today is already fully recorded (checked in and out).

### `POST /attendance/check-out`
No body. `200` with `working_minutes` computed. `409` if there's no active check-in, or
today's attendance is already checked out.

```json
// 200
{ "data": { "id": 42, "employee_id": 3, "employee_name": null, "date": "2026-08-01", "check_in_at": "2026-08-01T09:00:00+05:30", "check_out_at": "2026-08-01T18:00:00+05:30", "working_minutes": 540, "status": "present", "source": "manual" } }
```

### `GET /attendance`
Role-scoped (own / department / all). Query params: `employee_id` (admin only),
`from`, `to` (`Y-m-d`), `status`. Cursor-paginated.

---

## Performance

### `POST /performance` — admin (anyone) or manager (own department)

```json
// Request
{ "employee_id": 5, "month": "2026-08", "score": 8, "comment": "Great sprint." }
```
`201` with the created score. `422` if `score` is outside 1-10 or `month` isn't `Y-m`. `409` if
a score for this employee/month already exists. `403` if a manager targets an employee outside
their department, or the caller is an employee.

Recording a score queues a database notification to the employee
(`PerformanceScoreAdded`) — visible via Laravel's standard `$user->notifications` relation.

### `GET /performance`
Role-scoped (own history / department / all, via `employee_id` filter for admins). Query
params: `employee_id`, `month`.

---

## Import

### `POST /import` — admin only, `multipart/form-data`

Field: `file` (CSV, ≤100MB). Header row: `employee_email,date,check_in,check_out,performance`.

`202` immediately — processing happens on a queue worker:
```json
{ "data": { "id": 7, "original_filename": "aug.csv", "status": "pending", "total_rows": null, "processed_rows": 0, ... } }
```
`409` if this exact file (by content hash) was already uploaded. `422` for a non-CSV/oversized
file. `403` for non-admins.

Per-row failure reasons recorded in `import_row_errors` (visible via `GET /import/{id}`):
`employee not found`, `invalid date`, `invalid check-in/check-out sequence`,
`invalid score`, `duplicate attendance`, `employee email is missing or invalid`,
`performance requires a date`, `row has no attendance or performance data`.

### `GET /import` — admin only
Cursor-paginated list of past batches.

### `GET /import/{id}` — admin only
One batch, including its `row_errors` (`row_number`, `reason`).

---

## Analytics

### `GET /analytics?month=2026-08` — any authenticated user, response shaped by role

**Admin** (`scope: "company"`):
```json
{ "data": {
  "scope": "company", "month": "2026-08",
  "total_employees": 42,
  "attendance_percentage": 91.3,
  "average_performance_score": 7.4,
  "top_performers": [{ "employee_id": 5, "name": "...", "score": 10 }, "... up to 5"],
  "employees_below_60_percent_attendance": [{ "id": 9, "name": "...", "email": "..." }]
} }
```

**Manager** (`scope: "department"`): `department_id`, `attendance_percentage`,
`average_performance_score`, `employees_with_irregular_attendance` (attendance < 75% this
month) — scoped to their own department.

**Employee** (`scope: "self"`): their own `attendance_percentage` and
`average_performance_score`.

`month` defaults to the current month if omitted. Cached 30 minutes; invalidated on any
Attendance/PerformanceScore write (see `docs/ARCHITECTURE.md`).

---

## Reports — admin only, streamed CSV download

### `GET /reports/attendance?from=2026-08-01&to=2026-08-31`
Columns: `employee_email,employee_name,date,check_in,check_out,working_minutes,status`.

### `GET /reports/performance?month=2026-08`
Columns: `employee_email,employee_name,month,score,comment`.

Both stream row-by-row (`cursor()`) — memory use does not grow with result size.
