<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user, Hotel $hotel): bool
    {
        return $user->hasPermissionForHotel('users.view', $hotel);
    }

    public function manage(User $user, Hotel $hotel): bool
    {
        return $user->hasPermissionForHotel('users.manage', $hotel);
    }
}
