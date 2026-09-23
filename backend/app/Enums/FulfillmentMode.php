<?php

namespace App\Enums;

enum FulfillmentMode: string
{
    use EnumValues;

    case Kitchen = 'kitchen';
    case Direct = 'direct';
}
