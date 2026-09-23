<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    use EnumValues;

    case Draft = 'draft';
    case Issued = 'issued';
    case Voided = 'voided';
}
