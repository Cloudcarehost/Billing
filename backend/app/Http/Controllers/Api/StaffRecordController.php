<?php

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\StaffRecord;
use App\Services\StaffRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffRecordController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'record_type' => ['nullable', Rule::in($this->types())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $records = StaffRecord::query()
            ->where('hotel_id', $hotel->id)
            ->with(['user:id,name,email', 'creator:id,name', 'updater:id,name'])
            ->when($data['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($data['record_type'] ?? null, fn ($query, $type) => $query->where('record_type', $type))
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereDate('start_date', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereDate('start_date', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $like = "%{$search}%";
                $nested->where('staff_name_snapshot', 'like', $like)->orWhere('role_name_snapshot', 'like', $like)->orWhere('note', 'like', $like);
            }))
            ->orderByDesc('start_date')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 30));

        return $this->paginated($records);
    }

    public function store(Request $request, StaffRecordService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $record = $service->create($hotel, $request->user(), $request->validate($this->rules()));

        return $this->success($record, 'Staff record added.', 201);
    }

    public function update(Request $request, int $record, StaffRecordService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $record = StaffRecord::query()->where('hotel_id', $hotel->id)->findOrFail($record);

        return $this->success($service->update($record, $hotel, $request->user(), $request->validate($this->rules(true))), 'Staff record updated.');
    }

    public function destroy(Request $request, int $record, StaffRecordService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $record = StaffRecord::query()->where('hotel_id', $hotel->id)->findOrFail($record);
        $service->delete($record, $hotel, $request->user());

        return $this->success(null, 'Staff record removed.');
    }

    private function rules(bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'user_id' => [$required, 'integer'],
            'record_type' => [$required, Rule::in($this->types())],
            'start_date' => [$required, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'leave_duration' => ['nullable', Rule::requiredIf(fn () => request('record_type') === 'leave'), Rule::in(['full_day', 'first_half', 'second_half'])],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99'],
            'note' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => request('record_type') === 'note')],
        ];
    }

    private function types(): array
    {
        return ['leave', 'advance_paid', 'extra_paid', 'note'];
    }
}
