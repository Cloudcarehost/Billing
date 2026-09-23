<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('viewAny', [User::class, $hotel]);

        $perPage = min((int) $request->integer('per_page', 20), 100);
        $users = $hotel->users()
            ->select('users.*')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn ($builder) => $builder
                    ->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%"));
            })
            ->orderBy('users.name')
            ->paginate($perPage);

        $roles = $hotel->roles()->get()->keyBy('id');
        $users->through(fn (User $user) => $this->membershipData($user, $roles));

        return $this->paginated($users);
    }

    public function store(Request $request, HotelMembershipService $memberships): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('manage', [User::class, $hotel]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()],
            'role_id' => ['required', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'pay_cycle' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
        ]);

        $existing = User::query()->where('email', $data['email'])->exists();
        if (! $existing && empty($data['password'])) {
            return response()->json([
                'success' => false,
                'message' => 'A password is required for a new user.',
                'errors' => ['password' => ['A password is required for a new user.']],
            ], 422);
        }

        $user = $memberships->create($hotel, [
            ...$data,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $membership = $hotel->users()->whereKey($user->id)->firstOrFail();
        $role = $hotel->roles()->findOrFail($membership->pivot->role_id);

        return $this->success($this->membershipData($membership, collect([$role->id => $role])), 'User access created.', 201);
    }

    public function update(Request $request, int $user, HotelMembershipService $memberships): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('manage', [User::class, $hotel]);
        $user = $hotel->users()->whereKey($user)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['prohibited'],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()],
            'role_id' => ['required', 'integer'],
            'is_active' => ['required', 'boolean'],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'pay_cycle' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
        ]);

        $currentRole = $hotel->roles()->findOrFail($user->pivot->role_id);
        $nextRole = $hotel->roles()->findOrFail($data['role_id']);
        $removesLastOwner = $currentRole->is_owner && (! $data['is_active'] || ! $nextRole->is_owner);

        if ($removesLastOwner && $this->activeOwnerCount($hotel) <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'A hotel must retain at least one active Owner.',
            ], 422);
        }

        $updated = $memberships->update($hotel, $user, $data);
        $membership = $hotel->users()->whereKey($updated->id)->firstOrFail();
        $role = $hotel->roles()->findOrFail($membership->pivot->role_id);

        return $this->success($this->membershipData($membership, collect([$role->id => $role])), 'User access updated.');
    }

    private function activeOwnerCount(Hotel $hotel): int
    {
        return DB::table('hotel_user')
            ->join('roles', 'roles.id', '=', 'hotel_user.role_id')
            ->where('hotel_user.hotel_id', $hotel->id)
            ->where('hotel_user.is_active', true)
            ->where('roles.is_owner', true)
            ->count();
    }

    /** @param Collection<int, Role> $roles */
    private function membershipData(User $user, $roles): array
    {
        $role = $roles->get($user->pivot->role_id);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'membership' => [
                'is_active' => (bool) $user->pivot->is_active,
                'joined_at' => $user->pivot->joined_at,
                'salary_amount' => $user->pivot->salary_amount,
                'pay_cycle' => $user->pivot->pay_cycle,
                'role' => $role?->only(['id', 'name', 'slug', 'is_owner']),
                'outlet_ids' => DB::table('hotel_user_outlets')->where('hotel_id', $user->pivot->hotel_id)->where('user_id', $user->id)->pluck('outlet_id')->map(fn ($id) => (int) $id)->all(),
            ],
        ];
    }
}
