<?php

namespace Modules\Attendance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Attendance\Models\Holiday;

class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        // public holidays for the demo year, edit to taste
        $holidays = [
            '2026-01-26' => 'Republic Day',
            '2026-03-04' => 'Holi',
            '2026-08-15' => 'Independence Day',
            '2026-10-02' => 'Gandhi Jayanti',
            '2026-11-08' => 'Diwali',
            '2026-12-25' => 'Christmas',
        ];

        foreach ($holidays as $date => $name) {
            Holiday::firstOrCreate(['date' => $date], ['name' => $name]);
        }
    }
}
