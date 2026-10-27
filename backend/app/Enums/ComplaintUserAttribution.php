<?php

namespace App\Enums;

enum ComplaintUserAttribution: string
{
    use EnumValues;

    case Mentioned = 'mentioned';
    case Responsible = 'responsible';
    case Cleared = 'cleared';
}
