<?php

namespace App\Enums;

enum IdempotencyStatus: string
{
    use EnumValues;

    case Processing = 'processing';
    case Completed = 'completed';
}
