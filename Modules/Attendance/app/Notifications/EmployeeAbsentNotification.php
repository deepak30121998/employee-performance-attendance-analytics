<?php

namespace Modules\Attendance\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EmployeeAbsentNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly User $employee,
        private readonly string $date,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->name,
            'date' => $this->date,
        ];
    }
}
