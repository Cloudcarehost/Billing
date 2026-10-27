<?php

namespace App\Enums;

enum ComplaintReason: string
{
    use EnumValues;

    case TasteQuality = 'taste_quality';
    case Preparation = 'preparation';
    case Temperature = 'temperature';
    case WrongItem = 'wrong_item';
    case MissingQuantity = 'missing_quantity';
    case Delay = 'delay';
    case Hygiene = 'hygiene';
    case AllergyDietary = 'allergy_dietary';
    case StaffBehaviour = 'staff_behaviour';
    case Billing = 'billing';
    case Other = 'other';
}
