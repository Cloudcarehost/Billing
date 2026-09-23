<?php

namespace App\Enums;

enum DiningSessionStatus: string
{
    use EnumValues;

    case Occupied = 'occupied';
    case PendingBill = 'pending_bill';
    case Closed = 'closed';
}
