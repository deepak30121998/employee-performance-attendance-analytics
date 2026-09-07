<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            // copied from users.department_id at write time, on purpose not synced on
            // transfer - history stays with the dept it happened in. gives the manager
            // listing an index to range-scan (see ARCHITECTURE.md)
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            // Calendar day in the app's display timezone, not the server/UTC day - see ARCHITECTURE.md.
            $table->date('date');
            // Nullable: the daily absentee scheduler creates a row with no check-in at all.
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->unsignedInteger('working_minutes')->nullable();
            $table->enum('status', ['present', 'absent', 'half_day', 'on_leave'])->default('present');
            $table->enum('source', ['manual', 'import', 'system'])->default('manual');
            $table->timestamps();

            // One row per employee per day: the DB-level guard against a duplicate/concurrent check-in.
            $table->unique(['employee_id', 'date']);
            // analytics aggregates read only these three columns -> index-only scan
            $table->index(['date', 'status', 'employee_id']);
            // manager listing: dept + date range scan, see ARCHITECTURE.md for the EXPLAIN
            $table->index(['department_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
