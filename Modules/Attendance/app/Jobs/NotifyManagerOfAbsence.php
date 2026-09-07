<?php

namespace Modules\Attendance\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Attendance\Notifications\EmployeeAbsentNotification;
use Modules\User\Enums\Role;

class NotifyManagerOfAbsence implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // lock TTL so a SIGKILLed worker doesn't leave the id locked forever
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly int $employeeId,
        private readonly string $date,
    ) {}

    // ShouldBeUnique keeps two workers from racing the same employee/date;
    // the query below covers sequential retries after the lock expires
    public function uniqueId(): string
    {
        return "absence:{$this->employeeId}:{$this->date}";
    }

    public function handle(): void
    {
        $employee = User::find($this->employeeId);

        if (! $employee || $employee->department_id === null) {
            return;
        }

        $managers = User::query()
            ->where('department_id', $employee->department_id)
            ->where('role', Role::Manager)
            ->get();

        foreach ($managers as $manager) {
            $alreadyNotified = DatabaseNotification::query()
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $manager->id)
                ->where('type', EmployeeAbsentNotification::class)
                ->where('data->employee_id', $employee->id)
                ->where('data->date', $this->date)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $manager->notify(new EmployeeAbsentNotification($employee, $this->date));
        }
    }
}
