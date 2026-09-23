<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDeviceSession;
use App\Support\HotelDate;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'hotel_id' => ['sometimes', 'integer'],
        ]);

        if (! Auth::guard('web')->attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $credentials['remember'] ?? false,
        )) {
            Log::warning('Login failed.', ['email' => $credentials['email'], 'ip' => $request->ip()]);

            return response()->json(['success' => false, 'message' => 'The provided credentials are incorrect.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $context = $this->context($user, $credentials['hotel_id'] ?? null);

        if ($context === null) {
            Auth::guard('web')->logout();

            return response()->json([
                'success' => false,
                'message' => 'Your account does not have access to an active hotel.',
            ], 403);
        }

        $request->session()->regenerate();
        $device = UserDeviceSession::query()->updateOrCreate(['session_id' => $request->session()->getId()], ['user_id' => $user->id, 'device_name' => substr((string) $request->userAgent(), 0, 255), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'last_seen_at' => now(), 'revoked_at' => null]);
        $request->session()->put('device_session_id', $device->id);
        ActivityLog::query()->create(['hotel_id' => $context['hotel']->id, 'user_id' => $user->id, 'action' => 'auth.login', 'properties' => ['ip' => $request->ip()]]);

        return $this->success($this->accountData($user, $context), 'Logged in successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        UserDeviceSession::query()->where('session_id', $request->session()->getId())->update(['revoked_at' => now()]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(message: 'Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->accountData($request->user(), [
            'hotel' => $request->attributes->get('currentHotel'),
            'role' => $request->attributes->get('currentRole'),
            'permissions' => $request->attributes->get('currentPermissions', []),
        ]));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => $data['email']]);

        return $this->success(message: 'If that email address exists, a password reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['success' => false, 'message' => __($status)], 422);
        }

        return $this->success(message: 'Your password has been reset.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->session()->regenerate();

        return $this->success(message: 'Password changed successfully.');
    }

    /** @return array{hotel: Hotel, role: Role, permissions: array<int, string>}|null */
    private function context(User $user, ?int $hotelId): ?array
    {
        $hotel = $user->hotels()
            ->wherePivot('is_active', true)
            ->when($hotelId !== null, fn ($query) => $query->whereKey($hotelId))
            ->orderBy('hotels.id')
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

        return ['hotel' => $hotel, 'role' => $role, 'permissions' => $role->permissions->pluck('code')->all()];
    }

    /** @param array{hotel: Hotel, role: Role, permissions: array<int, string>} $context */
    private function accountData(User $user, array $context): array
    {
        $hotelWide = $context['role']->is_owner || in_array('outlets.all', $context['permissions'], true);
        $outlets = $context['hotel']->outlets()->where('is_active', true)
            ->when(! $hotelWide, fn ($query) => $query->whereIn('id', $user->accessibleOutlets()->where('outlets.hotel_id', $context['hotel']->id)->select('outlets.id')))
            ->orderBy('name')->get(['id', 'name', 'code', 'order_flow']);

        return [
            'user' => $user->only(['id', 'name', 'email']),
            'hotel' => $context['hotel']->only([
                'id', 'name', 'slug', 'currency_code', 'timezone', 'business_day_starts_at',
                'last_ended_business_date', 'is_active',
            ]) + ['current_business_date' => HotelDate::businessDate($context['hotel'])],
            'role' => $context['role']->only(['id', 'name', 'slug', 'is_owner']),
            'permissions' => $context['role']->is_owner ? ['*'] : $context['permissions'],
            'outlets' => $outlets,
            'hotel_wide_outlet_access' => $hotelWide,
        ];
    }
}
