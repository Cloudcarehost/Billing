<?php

namespace App\Enums;

enum ComplaintOutcome: string
{
    use EnumValues;

    case RestaurantIssue = 'restaurant_issue';
    case SharedResponsibility = 'shared_responsibility';
    case CustomerMisunderstanding = 'customer_misunderstanding';
    case Inconclusive = 'inconclusive';
}
