<?php

namespace App\Enums;

enum OutletOrderFlow: string
{
    use EnumValues;

    case Kitchen = 'kitchen';
    case DirectBill = 'direct_bill';
}
