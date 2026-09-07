<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Analytics\Support\AnalyticsCache;
use Modules\Attendance\Database\Seeders\AttendanceDatabaseSeeder;
use Modules\Attendance\Database\Seeders\HolidaySeeder;
use Modules\Performance\Database\Seeders\PerformanceDatabaseSeeder;
use Modules\User\Database\Seeders\UserDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserDatabaseSeeder::class);
        $this->call(HolidaySeeder::class);
        $this->call(AttendanceDatabaseSeeder::class);
        $this->call(PerformanceDatabaseSeeder::class);

        // seeders write via the query builder, so the observers never fired.
        // best effort - seeding shouldn't die just because redis is down
        try {
            app(AnalyticsCache::class)->flush();
        } catch (\Throwable) {
        }
    }
}
