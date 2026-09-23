<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\UserDeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SecurityController extends ApiController
{
    public function sessions(Request $request): JsonResponse
    {
        return $this->success(UserDeviceSession::query()->where('user_id', $request->user()->id)->latest('last_seen_at')->get());
    }

    public function revokeSession(Request $request, int $session): JsonResponse
    {
        $device = UserDeviceSession::query()->where('user_id', $request->user()->id)->findOrFail($session);
        $device->update(['revoked_at' => now()]);
        DB::table('sessions')->where('id', $device->session_id)->delete();
        if ($request->hasSession() && $device->session_id === $request->session()->getId()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->success($device->fresh(), 'Device session revoked.');
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $current = $request->session()->getId();
        $ids = UserDeviceSession::query()->where('user_id', $request->user()->id)->where('session_id', '!=', $current)->whereNull('revoked_at')->pluck('session_id');
        DB::table('sessions')->whereIn('id', $ids)->delete();
        UserDeviceSession::query()->whereIn('session_id', $ids)->update(['revoked_at' => now()]);

        return $this->success(message: 'Other device sessions revoked.');
    }

    public function audit(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->paginated(ActivityLog::query()->where('hotel_id', $hotel->id)->with('user:id,name,email', 'outlet:id,name')->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')->toString()))->latest('occurred_at')->paginate(min($request->integer('per_page', 50), 100)));
    }
}
