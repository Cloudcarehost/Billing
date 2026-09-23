<?php

namespace App\Enums;

enum TaxType: string
{
    use EnumValues;

    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
