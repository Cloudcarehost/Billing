<?php

namespace App\Policies;

use App\Models\Outlet;
use App\Models\User;

class OutletPolicy
{
    public function view(User $user, Outlet $outlet): bool
    {
        return $user->hasPermissionForHotel('settings.manage', $outlet->hotel);
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $user->hasPermissionForHotel('settings.manage', $outlet->hotel);
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $this->update($user, $outlet);
    }
}
