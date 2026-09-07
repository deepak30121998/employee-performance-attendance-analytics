<?php

namespace Modules\Attendance\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case OnLeave = 'on_leave';
}
