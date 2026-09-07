<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('disk_path');
            // sha256 of the file contents - a second upload of the same file is
            // rejected up front instead of being queued and reprocessed.
            $table->string('checksum', 64)->unique();
            $table->enum('status', ['pending', 'processing', 'completed', 'completed_with_errors', 'failed'])
                ->default('pending');
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('last_processed_row')->default(0);
            $table->unsignedInteger('imported_attendance_count')->default(0);
            $table->unsignedInteger('imported_performance_count')->default(0);
            $table->unsignedInteger('skipped_row_count')->default(0);
            $table->unsignedInteger('failed_row_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['uploaded_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
