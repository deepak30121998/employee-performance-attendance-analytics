# Entity-Relationship Diagram

```mermaid
erDiagram
    DEPARTMENTS ||--o{ USERS : "employs"
    USERS ||--o{ ATTENDANCES : "checks in/out"
    USERS ||--o{ PERFORMANCE_SCORES : "receives"
    USERS ||--o{ PERFORMANCE_SCORES : "records (as manager)"
    USERS ||--o{ IMPORT_BATCHES : "uploads"
    DEPARTMENTS ||--o{ ATTENDANCES : "denormalized for indexing"
    IMPORT_BATCHES ||--o{ IMPORT_ROW_ERRORS : "logs"

    DEPARTMENTS {
        bigint id PK
        string name UK
    }

    USERS {
        bigint id PK
        string name
        string email UK
        string password
        enum role "admin | manager | employee"
        bigint department_id FK "nullable"
        string designation "nullable"
        timestamp deleted_at "soft delete"
    }

    ATTENDANCES {
        bigint id PK
        bigint employee_id FK
        bigint department_id FK "denormalized from users.department_id at write time"
        date date
        timestamp check_in_at "nullable"
        timestamp check_out_at "nullable"
        int working_minutes "nullable"
        enum status "present | absent | half_day | on_leave"
        enum source "manual | import | system"
    }

    PERFORMANCE_SCORES {
        bigint id PK
        bigint employee_id FK
        date month "first-of-month"
        tinyint score "1-10, CHECK constraint"
        text comment "nullable"
        bigint created_by FK "nullable, the recording manager"
    }

    IMPORT_BATCHES {
        bigint id PK
        bigint uploaded_by FK
        string original_filename
        string disk_path
        string checksum UK "sha256, blocks duplicate uploads"
        enum status "pending|processing|completed|completed_with_errors|failed"
        int total_rows "nullable"
        int processed_rows
        int last_processed_row "resume cursor"
        int imported_attendance_count
        int imported_performance_count
        int skipped_row_count
        int failed_row_count
        timestamp started_at "nullable"
        timestamp finished_at "nullable"
    }

    IMPORT_ROW_ERRORS {
        bigint id PK
        bigint import_batch_id FK
        int row_number
        string reason
        json raw_row
    }

    HOLIDAYS {
        bigint id PK
        date date UK
        string name
    }

    DAILY_ATTENDANCE_SUMMARIES {
        bigint id PK
        date date UK "one summary per day"
        int total_employees
        int present_count
        int absent_count
        int newly_marked_absent
        timestamp generated_at
    }
```

## Notable constraints (not visible in an ERD)

- `attendances`: `UNIQUE(employee_id, date)` - the DB-level guarantee against duplicate/concurrent check-ins.
- `attendances`: `INDEX(date, status, employee_id)` - covering index for analytics aggregation.
- `attendances`: `INDEX(department_id, date)` - the manager-listing query's index (see `docs/ARCHITECTURE.md`).
- `performance_scores`: `UNIQUE(employee_id, month)` + a `CHECK (score BETWEEN 1 AND 10)` constraint.
- `import_batches`: `UNIQUE(checksum)` - rejects a byte-identical re-upload before it's queued.

`users.department_id` and `attendances.department_id` are **not** kept in sync after the fact -
see `docs/ARCHITECTURE.md`'s note on why that's intentional.
