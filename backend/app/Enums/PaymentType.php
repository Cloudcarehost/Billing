<?php

namespace App\Enums;

enum PaymentType: string
{
    use EnumValues;

    case Payment = 'payment';
    case Refund = 'refund';
}
