<?php

namespace App\Enums;

enum ComplaintResolutionType: string
{
    use EnumValues;

    case ItemReplaced = 'item_replaced';
    case ApologyExplanation = 'apology_explanation';
    case ComplimentaryItem = 'complimentary_item';
    case DiscountRefundSeparate = 'discount_refund_separate';
    case StaffCoaching = 'staff_coaching';
    case NoActionRequired = 'no_action_required';
    case Other = 'other';
}
