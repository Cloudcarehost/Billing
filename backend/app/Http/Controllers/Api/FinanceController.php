<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\LedgerEntry;
use App\Models\StandingCost;
use App\Services\FinanceService;
use App\Support\HotelDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceController extends ApiController
{
    public function summary(Request $request, FinanceService $finance): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success($finance->summary($hotel, ...array_values($this->range($request, $hotel))));
    }

    public function entries(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        [$from, $to] = array_values($this->range($request, $hotel));

        return $this->success(
            LedgerEntry::query()
                ->where('hotel_id', $hotel->id)
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
            'created_by' => $request->user()->id,
        ]);

        return $this->success($entry->load('creator:id,name'), 'Entry saved.', 201);
    }

    public function updateEntry(Request $request, int $entry): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $entry = LedgerEntry::query()->where('hotel_id', $hotel->id)->findOrFail($entry);
        $entry->update($this->entryData($request, $entry));

        return $this->success($entry->fresh()->load('creator:id,name'), 'Entry updated.');
    }

    public function destroyEntry(Request $request, int $entry): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $entry = LedgerEntry::query()->where('hotel_id', $hotel->id)->findOrFail($entry);
        $entry->delete();

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

        return $this->success($cost, 'Fixed bill saved.', 201);
    }

    public function updateStandingCost(Request $request, int $cost): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $cost = StandingCost::query()->where('hotel_id', $hotel->id)->findOrFail($cost);
        $cost->update($this->standingData($request, $cost));

        return $this->success($cost->fresh(), 'Fixed bill updated.');
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
}
