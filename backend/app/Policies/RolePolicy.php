<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user, Hotel $hotel): bool
    {
        return $user->hasPermissionForHotel('users.view', $hotel);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionForHotel('users.view', $role->hotel);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionForHotel('users.manage', $role->hotel);
    }
}
