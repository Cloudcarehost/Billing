<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HotelMembershipService
{
    public function __construct(private readonly AuthAccessCache $access) {}

    /** @param array{name: string, email: string, password?: string, role_id: int, is_active: bool, outlet_ids?: array<int, int>, salary_amount?: mixed, pay_cycle?: ?string} $data */
    public function create(Hotel $hotel, array $data): User
    {
        $user = DB::transaction(function () use ($hotel, $data): User {
            $user = User::query()->where('email', $data['email'])->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);
            }

            $role = $this->role($hotel, $data['role_id']);
            $hotel->users()->syncWithoutDetaching([
                $user->id => [
                    'role_id' => $role->id,
                    'is_active' => $data['is_active'],
                    'joined_at' => now(),
                    ...$this->payPayload($data, true),
                ],
            ]);
            $this->syncOutlets($hotel, $user, $data['outlet_ids'] ?? $hotel->outlets()->pluck('id')->all());

            return $user->fresh();
        });
        $this->access->forgetUser($user->id);

        return $user;
    }

    /** @param array{name?: string, password?: string, role_id: int, is_active: bool, outlet_ids?: array<int, int>, salary_amount?: mixed, pay_cycle?: ?string} $data */
    public function update(Hotel $hotel, User $user, array $data): User
    {
        $user = DB::transaction(function () use ($hotel, $user, $data): User {
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();

            $role = $this->role($hotel, $data['role_id']);
            $hotel->users()->updateExistingPivot($user->id, [
                'role_id' => $role->id,
                'is_active' => $data['is_active'],
                ...$this->payPayload($data),
            ]);
            if (array_key_exists('outlet_ids', $data)) {
                $this->syncOutlets($hotel, $user, $data['outlet_ids']);
            }

            return $user->fresh();
        });
        $this->access->forgetUser($user->id);

        return $user;
    }

    /** @return array{salary_amount: mixed, pay_cycle: ?string}|array{} */
    private function payPayload(array $data, bool $required = false): array
    {
        if (! $required && ! array_key_exists('salary_amount', $data) && ! array_key_exists('pay_cycle', $data)) {
            return [];
        }
        $amount = $data['salary_amount'] ?? null;
        if ($amount === '' || $amount === null || (float) $amount <= 0) {
            return ['salary_amount' => null, 'pay_cycle' => null];
        }
        $cycle = $data['pay_cycle'] ?? null;
        abort_unless(in_array($cycle, ['daily', 'weekly', 'monthly'], true), 422, 'Select a pay cycle for salary.');

        return ['salary_amount' => $amount, 'pay_cycle' => $cycle];
    }

    private function role(Hotel $hotel, int $roleId): Role
    {
        return Role::query()
            ->where('hotel_id', $hotel->id)
            ->findOrFail($roleId);
    }

    /** @param array<int, int> $outletIds */
    private function syncOutlets(Hotel $hotel, User $user, array $outletIds): void
    {
        $validIds = $hotel->outlets()->whereIn('id', $outletIds)->pluck('id');
        abort_if($validIds->count() !== count(array_unique($outletIds)), 422, 'One or more outlet assignments are invalid.');
        DB::table('hotel_user_outlets')->where('hotel_id', $hotel->id)->where('user_id', $user->id)->delete();
        DB::table('hotel_user_outlets')->insert($validIds->map(fn (int $outletId) => [
            'hotel_id' => $hotel->id, 'user_id' => $user->id, 'outlet_id' => $outletId,
            'created_at' => now(), 'updated_at' => now(),
        ])->all());
    }
}
