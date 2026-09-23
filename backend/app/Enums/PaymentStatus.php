<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use EnumValues;

    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
}
