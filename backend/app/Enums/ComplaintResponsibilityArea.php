<?php

namespace App\Enums;

enum ComplaintResponsibilityArea: string
{
    use EnumValues;

    case Kitchen = 'kitchen';
    case WaiterService = 'waiter_service';
    case Management = 'management';
    case SystemProcess = 'system_process';
    case Customer = 'customer';
    case Unknown = 'unknown';
}
