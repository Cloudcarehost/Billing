<?php

namespace App\Http\Controllers\Api;

use App\Enums\InventoryDeductionRule;
use App\Models\DiningSession;
use App\Models\Hotel;
use App\Services\AuditService;
use App\Services\AuthAccessCache;
use App\Support\HotelDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HotelController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('view', $hotel);

        return $this->success(Hotel::query()->findOrFail($hotel->id));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('update', $hotel);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'state_code' => ['nullable', 'string', 'size:2'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone'],
            'business_day_starts_at' => ['sometimes', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'inventory_deduction_rule' => ['sometimes', Rule::enum(InventoryDeductionRule::class)],
            'alert_sound_kitchen' => ['sometimes', 'string', Rule::in(['chirp', 'ding', 'double', 'chime', 'kitchen', 'waiter'])],
            'alert_sound_ready' => ['sometimes', 'string', Rule::in(['chirp', 'ding', 'double', 'chime', 'kitchen', 'waiter'])],
            'alert_sound_call' => ['sometimes', 'string', Rule::in(['chirp', 'ding', 'double', 'chime', 'kitchen', 'waiter'])],
            'alert_sound_billing' => ['sometimes', 'string', Rule::in(['chirp', 'ding', 'double', 'chime', 'kitchen', 'waiter'])],
            'slug' => ['prohibited'],
        ]);

        if (isset($data['business_day_starts_at'])) {
            $data['business_day_starts_at'] = substr($data['business_day_starts_at'], 0, 5).':00';
        }

        $hotel->update($data);
        app(AuthAccessCache::class)->forgetHotel($hotel->id);

        return $this->success($hotel->fresh(), 'Hotel settings updated.');
    }

    public function endDay(Request $request, AuditService $audit): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        Gate::authorize('update', $hotel);

        $businessDate = HotelDate::businessDate($hotel);
        $openSessions = DiningSession::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', ['occupied', 'pending_bill'])
            ->count();
        $alreadyEnded = $hotel->last_ended_business_date?->toDateString() === $businessDate;

        if (! $alreadyEnded) {
            $hotel->update(['last_ended_business_date' => $businessDate]);
            $audit->record($hotel, 'hotel.business_day_ended', $request->user(), $hotel->id, null, [
                'business_date' => $businessDate,
                'open_sessions' => $openSessions,
            ]);
            app(AuthAccessCache::class)->forgetHotel($hotel->id);
        }

        return $this->success([
            'business_date' => $businessDate,
            'open_sessions' => $openSessions,
            'already_ended' => $alreadyEnded,
        ], $alreadyEnded
            ? 'This business day is already closed.'
            : ($openSessions > 0
                ? "Business day ended with {$openSessions} table(s) still open."
                : 'Business day ended.'));
    }
}
