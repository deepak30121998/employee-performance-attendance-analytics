<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_attendance_summaries', function (Blueprint $table) {
            $table->id();
            // one summary per day; re-running the command updates in place
            $table->date('date')->unique();
            $table->unsignedInteger('total_employees');
            $table->unsignedInteger('present_count');
            $table->unsignedInteger('absent_count');
            $table->unsignedInteger('newly_marked_absent');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendance_summaries');
    }
};
