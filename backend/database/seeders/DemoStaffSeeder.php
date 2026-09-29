<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Role;
use App\Services\HotelMembershipService;
use Illuminate\Database\Seeder;

class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $hotel = Hotel::query()->first();
        if (! $hotel) {
            $this->command?->warn('No hotel found. Complete /setup first, then seed staff.');

            return;
        }

        $roles = Role::query()->where('hotel_id', $hotel->id)->get()->keyBy('slug');
        $memberships = app(HotelMembershipService::class);
        $password = 'StaffPass1';

        foreach ([
            ['manager', 'Priya Manager', 'manager@aswad.test'],
            ['cashier', 'Kiran Cashier', 'cashier@aswad.test'],
            ['waiter', 'Waseem Waiter', 'waiter@aswad.test'],
            ['kitchen', 'Chef Arun', 'kitchen@aswad.test'],
        ] as [$slug, $name, $email]) {
            $role = $roles->get($slug);
            if (! $role) {
                $this->command?->warn("Missing role {$slug}; skip {$email}.");
                continue;
            }

            $memberships->create($hotel, [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role_id' => $role->id,
                'is_active' => true,
                'outlet_ids' => $hotel->outlets()->pluck('id')->all(),
            ]);
        }
    }
}
