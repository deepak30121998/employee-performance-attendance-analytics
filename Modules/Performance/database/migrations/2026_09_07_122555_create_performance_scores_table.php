<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            // First-of-month date; one score per employee per month.
            $table->date('month');
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
            $table->index(['month']);
        });

        // App-layer validation already rejects out-of-range scores; this is the
        // belt-and-suspenders DB guard against any write that bypasses it (e.g. a raw import upsert).
        DB::statement('ALTER TABLE performance_scores ADD CONSTRAINT chk_performance_score_range CHECK (score BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_scores');
    }
};
