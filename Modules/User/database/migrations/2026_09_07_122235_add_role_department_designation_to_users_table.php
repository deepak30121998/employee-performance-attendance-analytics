<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Native DB enum gives us a hard constraint against invalid roles;
            // Modules\User\Enums\Role mirrors these values for app-level type safety.
            $table->enum('role', ['admin', 'manager', 'employee'])->default('employee')->after('password');
            $table->foreignId('department_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->string('designation')->nullable()->after('department_id');
            $table->softDeletes()->after('updated_at');

            // Primary access pattern: "employees in department X" scoped by role.
            $table->index(['department_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'role']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['role', 'designation']);
            $table->dropSoftDeletes();
        });
    }
};
