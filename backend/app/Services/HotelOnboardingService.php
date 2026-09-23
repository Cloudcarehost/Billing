<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HotelOnboardingService
{
    public function __construct(private readonly RolePresetService $rolePresets) {}

    /** @param array<string, mixed> $data */
    public function createFirstHotel(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $user = User::query()->create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['password'],
            ]);

            $hotel = Hotel::query()->create([
                'name' => $data['hotel_name'],
                'slug' => $this->uniqueSlug($data['hotel_name']),
                'legal_name' => $data['legal_name'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'INR',
                'timezone' => $data['timezone'] ?? config('app.timezone'),
                'phone' => $data['phone'] ?? null,
                'email' => $data['hotel_email'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            $roles = $this->rolePresets->createDefaults($hotel);
            $hotel->users()->attach($user->id, [
                'role_id' => $roles['owner']->id,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            $outlet = Outlet::query()->create([
                'hotel_id' => $hotel->id,
                'name' => $data['outlet_name'],
                'code' => Str::upper($data['outlet_code']),
                'invoice_prefix' => Str::upper($data['invoice_prefix'] ?? 'INV'),
            ]);
            DB::table('hotel_user_outlets')->insert([
                'hotel_id' => $hotel->id, 'user_id' => $user->id, 'outlet_id' => $outlet->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return compact('user', 'hotel', 'outlet');
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'hotel';
        $slug = $base;
        $suffix = 2;

        while (Hotel::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
