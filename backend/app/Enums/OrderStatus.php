<?php

namespace App\Enums;

enum OrderStatus: string
{
    use EnumValues;

    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';
}
