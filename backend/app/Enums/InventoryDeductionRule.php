<?php

namespace App\Enums;

enum InventoryDeductionRule: string
{
    use EnumValues;

    case Preparing = 'preparing';
    case Served = 'served';
}
