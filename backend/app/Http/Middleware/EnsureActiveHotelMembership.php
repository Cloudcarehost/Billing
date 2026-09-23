<?php

namespace App\Http\Middleware;

use App\Models\UserDeviceSession;
use App\Services\AuthAccessCache;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveHotelMembership
{
    public function __construct(private readonly AuthAccessCache $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->error('Unauthenticated.', 401);
        }

        $device = null;
        if ($request->hasSession()) {
            $deviceId = $request->session()->get('device_session_id');
            $device = UserDeviceSession::query()
                ->when($deviceId, fn ($query) => $query->whereKey($deviceId))
                ->when(! $deviceId, fn ($query) => $query->where('session_id', $request->session()->getId()))
                ->first(['id', 'revoked_at', 'last_seen_at']);
        }

        if ($device?->revoked_at !== null) {
            return $this->error('This device session has been revoked.', 401);
        }

        if ($device !== null && ($device->last_seen_at === null || $device->last_seen_at->lte(now()->subMinutes(5)))) {
            $device->update(['last_seen_at' => now()]);
        }

        $hotelId = $request->header('X-Hotel-Id');
        if ($hotelId !== null && filter_var($hotelId, FILTER_VALIDATE_INT) === false) {
            return $this->error('The X-Hotel-Id header must be a valid hotel ID.', 422);
        }

        $context = $this->access->remember($user, $hotelId !== null ? (int) $hotelId : null);

        if ($context === null) {
            return $this->error('You do not have access to an active hotel.', 403);
        }

        $request->attributes->set('currentHotel', $context['hotel']);
        $request->attributes->set('currentRole', $context['role']);
        $request->attributes->set('currentPermissions', $context['permissions']);
        $request->attributes->set('accessibleOutletIds', $context['outlet_ids']);
        $request->attributes->set('hasHotelWideOutletAccess', $context['hotel_wide']);

        return $next($request);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
