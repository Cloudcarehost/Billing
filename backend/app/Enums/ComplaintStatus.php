<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    use EnumValues;

    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
