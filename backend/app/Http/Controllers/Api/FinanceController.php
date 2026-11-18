<?php

namespace App\Http\Controllers\Api;

use App\Models\DailyMoneyRegister;
use App\Models\Hotel;
use App\Models\LedgerEntry;
use App\Models\Outlet;
use App\Models\StandingCost;
use App\Services\AuditService;
use App\Services\DailyMoneyRegisterService;
use App\Services\FinanceService;
use App\Services\ReportService;
use App\Support\HotelDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends ApiController
{
    public function summary(Request $request, FinanceService $finance): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        [$from, $to] = array_values($this->range($request, $hotel));

        return $this->success($finance->summary($hotel, $from, $to, $request->attributes->get('currentOutletId')));
    }

    public function entries(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        [$from, $to] = array_values($this->range($request, $hotel));

        return $this->success(
            LedgerEntry::query()
                ->where('hotel_id', $hotel->id)
                ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id))
                ->whereBetween('occurred_on', [$from, $to])
                ->with('creator:id,name')
                ->orderByDesc('occurred_on')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function storeEntry(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $entry = LedgerEntry::query()->create([
            ...$this->entryData($request),
            'hotel_id' => $hotel->id,
            'outlet_id' => $this->outletId($request),
            'created_by' => $request->user()->id,
        ]);
        ReportService::invalidateDashboard($hotel->id);

        return $this->success($entry->load('creator:id,name'), 'Entry saved.', 201);
    }

    public function updateEntry(Request $request, int $entry): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $entry = LedgerEntry::query()
            ->where('hotel_id', $hotel->id)
            ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id))
            ->findOrFail($entry);
        $entry->update($this->entryData($request, $entry));
        ReportService::invalidateDashboard($hotel->id);

        return $this->success($entry->fresh()->load('creator:id,name'), 'Entry updated.');
    }

    public function destroyEntry(Request $request, int $entry): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $entry = LedgerEntry::query()
            ->where('hotel_id', $hotel->id)
            ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id))
            ->findOrFail($entry);
        $entry->delete();
        ReportService::invalidateDashboard($hotel->id);

        return $this->success(['id' => $entry->id], 'Entry deleted.');
    }

    public function standingCosts(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success(StandingCost::query()->where('hotel_id', $hotel->id)->orderBy('name')->get());
    }

    public function storeStandingCost(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $cost = StandingCost::query()->create([
            ...$this->standingData($request),
            'hotel_id' => $hotel->id,
        ]);
        ReportService::invalidateDashboard($hotel->id);

        return $this->success($cost, 'Fixed bill saved.', 201);
    }

    public function updateStandingCost(Request $request, int $cost): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $cost = StandingCost::query()->where('hotel_id', $hotel->id)->findOrFail($cost);
        $cost->update($this->standingData($request, $cost));
        ReportService::invalidateDashboard($hotel->id);

        return $this->success($cost->fresh(), 'Fixed bill updated.');
    }

    public function dailyRegister(Request $request, DailyMoneyRegisterService $registers): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        [$from, $to] = array_values($this->range($request, $hotel));

        return $this->success($registers->overview($hotel, $this->outletId($request), $from, $to));
    }

    public function storeDailyRegister(Request $request, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $outletId = $this->outletId($request);
        $data = $this->dailyRegisterData($request);
        if (DailyMoneyRegister::query()->where('outlet_id', $outletId)->whereDate('business_date', $data['business_date'])->exists()) {
            throw ValidationException::withMessages(['business_date' => ['A daily entry already exists for this date. Edit the existing entry instead.']]);
        }
        $register = DailyMoneyRegister::query()->create([
            ...$data,
            'hotel_id' => $hotel->id,
            'outlet_id' => $outletId,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $audit->record($register, 'finance.daily_register.created', $request->user(), $hotel->id, $outletId, $data);

        return $this->success($register->load(['creator:id,name', 'updater:id,name']), 'Daily balance saved.', 201);
    }

    public function updateDailyRegister(Request $request, int $register, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $outletId = $this->outletId($request);
        $register = DailyMoneyRegister::query()
            ->where('hotel_id', $hotel->id)
            ->where('outlet_id', $outletId)
            ->findOrFail($register);
        $before = $register->only(['business_date', 'actual_online', 'actual_cash', 'notes']);
        $data = $this->dailyRegisterData($request, $register);
        if (isset($data['business_date'])) {
            $duplicate = DailyMoneyRegister::query()
                ->where('outlet_id', $outletId)
                ->whereDate('business_date', $data['business_date'])
                ->whereKeyNot($register->id)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['business_date' => ['A daily entry already exists for this date.']]);
            }
        }
        $register->update([...$data, 'updated_by' => $request->user()->id]);
        $audit->record($register, 'finance.daily_register.updated', $request->user(), $hotel->id, $outletId, [
            'before' => $before,
            'after' => $register->fresh()->only(['business_date', 'actual_online', 'actual_cash', 'notes']),
        ]);

        return $this->success($register->fresh()->load(['creator:id,name', 'updater:id,name']), 'Daily balance updated.');
    }

    /** @return array{from: string, to: string} */
    private function range(Request $request, Hotel $hotel): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:today,week,month,year'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        if (empty($data['from'])) {
            [$data['from'], $data['to']] = HotelDate::rangeForPeriod($hotel, $data['period'] ?? 'month');
        }
        $data['to'] ??= $data['from'];

        return ['from' => $data['from'], 'to' => $data['to']];
    }

    /** @return array{type: string, category: string, amount: float, comment: ?string, occurred_on: string} */
    private function entryData(Request $request, ?LedgerEntry $entry = null): array
    {
        $data = $request->validate([
            'type' => [$entry ? 'sometimes' : 'required', Rule::in(['expense', 'income'])],
            'category' => [$entry ? 'sometimes' : 'required', 'string', 'max:80'],
            'amount' => [$entry ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'occurred_on' => [$entry ? 'sometimes' : 'required', 'date'],
        ]);
        if (isset($data['comment'])) {
            $data['comment'] = filled($data['comment']) ? trim((string) $data['comment']) : null;
        }

        return $data;
    }

    /** @return array{name?: string, amount?: float, pay_cycle?: string, due_on?: ?string, is_active?: bool} */
    private function standingData(Request $request, ?StandingCost $cost = null): array
    {
        return $request->validate([
            'name' => [$cost ? 'sometimes' : 'required', 'string', 'max:120'],
            'amount' => [$cost ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'pay_cycle' => [$cost ? 'sometimes' : 'required', Rule::in(['daily', 'weekly', 'monthly'])],
            'due_on' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array{business_date?: string, actual_online?: float, actual_cash?: float, notes?: ?string} */
    private function dailyRegisterData(Request $request, ?DailyMoneyRegister $register = null): array
    {
        $hotel = $request->attributes->get('currentHotel');
        $updating = $register !== null;
        $data = $request->validate([
            'business_date' => [$updating ? 'sometimes' : 'required', 'date', 'before_or_equal:'.HotelDate::businessDate($hotel)],
            'actual_online' => [$updating ? 'sometimes' : 'required_without:actual_cash', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'actual_cash' => [$updating ? 'sometimes' : 'required_without:actual_online', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $actualOnline = array_key_exists('actual_online', $data) ? $data['actual_online'] : $register?->actual_online;
        $actualCash = array_key_exists('actual_cash', $data) ? $data['actual_cash'] : $register?->actual_cash;
        if ($actualOnline === null && $actualCash === null) {
            throw ValidationException::withMessages([
                'actual_online' => ['Enter either the online balance or the cash balance.'],
            ]);
        }
        if (array_key_exists('notes', $data)) {
            $data['notes'] = filled($data['notes']) ? trim((string) $data['notes']) : null;
        }

        return $data;
    }

    private function outletId(Request $request): int
    {
        $outletId = $request->attributes->get('currentOutletId')
            ?? collect($request->attributes->get('accessibleOutletIds', []))->first();
        abort_if($outletId === null, 422, 'Select an outlet before using the daily register.');
        Outlet::query()
            ->where('hotel_id', $request->attributes->get('currentHotel')->id)
            ->whereIn('id', $request->attributes->get('accessibleOutletIds', []))
            ->findOrFail($outletId);

        return (int) $outletId;
    }
}
