<?php

namespace App\Enums;

enum PaymentMethod: string
{
    use EnumValues;

    case Cash = 'cash';
    case Card = 'card';
    case Upi = 'upi';
}
