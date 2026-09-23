<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthAccessCache
{
    public const TTL_SECONDS = 600;

    /**
     * @return array{hotel: Hotel, role: Role, permissions: array<int, string>, hotel_wide: bool, outlet_ids: array<int, int>}|null
     */
    public function remember(User $user, ?int $hotelId): ?array
    {
        $resolvedHotelId = $this->resolveHotelId($user, $hotelId);
        if ($resolvedHotelId === null) {
            return null;
        }

        $key = $this->contextKey($user->id, $resolvedHotelId);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $this->hydrate($cached);
        }

        $payload = $this->load($user, $resolvedHotelId);
        if ($payload === null) {
            return null;
        }

        Cache::put($key, $payload, self::TTL_SECONDS);

        return $this->hydrate($payload);
    }

    public function forgetUser(int $userId): void
    {
        $this->bump($this->userVersionKey($userId));
    }

    public function forgetRole(int $roleId): void
    {
        $userIds = DB::table('hotel_user')->where('role_id', $roleId)->pluck('user_id');
        foreach ($userIds as $userId) {
            $this->forgetUser((int) $userId);
        }
    }

    public function forgetHotel(int $hotelId): void
    {
        $this->bump($this->hotelVersionKey($hotelId));
    }

    private function resolveHotelId(User $user, ?int $hotelId): ?int
    {
        $id = $user->hotels()
            ->wherePivot('is_active', true)
            ->when($hotelId !== null, fn ($query) => $query->whereKey($hotelId))
            ->orderBy('hotels.id')
            ->value('hotels.id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{hotel: array<string, mixed>, role: array<string, mixed>, permissions: array<int, string>, hotel_wide: bool, outlet_ids: array<int, int>}|null */
    private function load(User $user, int $hotelId): ?array
    {
        $hotel = $user->hotels()
            ->wherePivot('is_active', true)
            ->whereKey($hotelId)
            ->first();

        if ($hotel === null || ! $hotel->is_active) {
            return null;
        }

        $role = Role::query()
            ->with('permissions:id,code')
            ->whereKey($hotel->pivot->role_id)
            ->where('hotel_id', $hotel->id)
            ->first();

        if ($role === null) {
            return null;
        }

        $permissions = $role->permissions->pluck('code')->all();
        $hotelWide = $role->is_owner || in_array('outlets.all', $permissions, true);
        $outletIds = $hotelWide
            ? Outlet::query()->where('hotel_id', $hotel->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $user->accessibleOutlets()->where('outlets.hotel_id', $hotel->id)->orderBy('outlets.id')->pluck('outlets.id')->map(fn ($id) => (int) $id)->all();

        $hotelAttrs = $hotel->only([
            'id', 'name', 'slug', 'legal_name', 'tax_number', 'gstin', 'state_code', 'currency_code',
            'timezone', 'business_day_starts_at', 'last_ended_business_date', 'phone', 'email', 'address',
            'is_active', 'allow_negative_stock', 'inventory_deduction_rule',
            'alert_sound_kitchen', 'alert_sound_ready', 'alert_sound_call', 'alert_sound_billing',
        ]);
        $hotelAttrs['last_ended_business_date'] = $hotel->last_ended_business_date?->toDateString();
        $hotelAttrs['business_day_starts_at'] = substr((string) ($hotel->business_day_starts_at ?: '05:00:00'), 0, 8);

        return [
            'hotel' => $hotelAttrs,
            'role' => $role->only(['id', 'hotel_id', 'name', 'slug', 'is_owner']),
            'permissions' => $permissions,
            'hotel_wide' => $hotelWide,
            'outlet_ids' => $outletIds,
        ];
    }

    /**
     * @param  array{hotel: array<string, mixed>, role: array<string, mixed>, permissions: array<int, string>, hotel_wide: bool, outlet_ids: array<int, int>}  $payload
     * @return array{hotel: Hotel, role: Role, permissions: array<int, string>, hotel_wide: bool, outlet_ids: array<int, int>}
     */
    private function hydrate(array $payload): array
    {
        $hotel = new Hotel();
        $hotel->setRawAttributes($payload['hotel'], true);
        $hotel->exists = true;

        $role = new Role();
        $role->setRawAttributes($payload['role'], true);
        $role->exists = true;

        return [
            'hotel' => $hotel,
            'role' => $role,
            'permissions' => $payload['permissions'],
            'hotel_wide' => (bool) $payload['hotel_wide'],
            'outlet_ids' => array_map('intval', $payload['outlet_ids']),
        ];
    }

    private function contextKey(int $userId, int $hotelId): string
    {
        return "auth.ctx.{$userId}.{$hotelId}.".$this->version($this->userVersionKey($userId)).'.'.$this->version($this->hotelVersionKey($hotelId));
    }

    private function userVersionKey(int $userId): string
    {
        return "auth.ver.user.{$userId}";
    }

    private function hotelVersionKey(int $hotelId): string
    {
        return "auth.ver.hotel.{$hotelId}";
    }

    private function version(string $key): int
    {
        $value = Cache::get($key);
        if ($value === null) {
            Cache::forever($key, 1);

            return 1;
        }

        return (int) $value;
    }

    private function bump(string $key): void
    {
        if (Cache::get($key) === null) {
            Cache::forever($key, 2);

            return;
        }

        Cache::increment($key);
    }
}
