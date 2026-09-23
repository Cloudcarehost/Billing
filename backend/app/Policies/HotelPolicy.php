<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\User;

class HotelPolicy
{
    public function view(User $user, Hotel $hotel): bool
    {
        return $user->hasPermissionForHotel('settings.manage', $hotel);
    }

    public function update(User $user, Hotel $hotel): bool
    {
        return $user->hasPermissionForHotel('settings.manage', $hotel);
    }
}
