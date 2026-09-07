<?php

namespace Modules\Attendance\Enums;

enum AttendanceSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case System = 'system';
}
