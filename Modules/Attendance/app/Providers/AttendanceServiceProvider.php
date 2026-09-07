<?php

namespace Modules\Attendance\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Console\Commands\MarkAbsenteesCommand;
use Modules\Attendance\Contracts\AttendanceRepositoryInterface;
use Modules\Attendance\Contracts\HolidayRepositoryInterface;
use Modules\Attendance\Models\Attendance;
use Modules\Attendance\Observers\AttendanceObserver;
use Modules\Attendance\Policies\AttendancePolicy;
use Modules\Attendance\Repositories\AttendanceRepository;
use Modules\Attendance\Repositories\HolidayRepository;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AttendanceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Attendance';

    protected string $nameLower = 'attendance';

    protected array $commands = [
        MarkAbsenteesCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(AttendanceRepositoryInterface::class, AttendanceRepository::class);
        $this->app->bind(HolidayRepositoryInterface::class, HolidayRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Attendance::class, AttendancePolicy::class);

        Attendance::observe(AttendanceObserver::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        // late enough that same-day check-ins have landed; a re-run for the
        // same date is a no-op anyway
        $schedule->command('attendance:mark-absentees')
            ->dailyAt('23:55')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
