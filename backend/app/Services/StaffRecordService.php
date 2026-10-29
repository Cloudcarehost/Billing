<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\StaffRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffRecordService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(Hotel $hotel, User $actor, array $data): StaffRecord
    {
        return DB::transaction(function () use ($hotel, $actor, $data) {
            $member = $hotel->users()->whereKey($data['user_id'])->firstOrFail();
            $role = $hotel->roles()->find($member->pivot->role_id);
            $this->validateLeaveRange($data);

            $record = StaffRecord::query()->create([
                ...$this->normalized($data),
                'hotel_id' => $hotel->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'staff_name_snapshot' => $member->name,
                'role_name_snapshot' => $role?->name,
                'currency_code_snapshot' => $hotel->currency_code,
            ]);
            $this->audit->record($record, 'staff_record.created', $actor, $hotel->id, null, [
                'staff_user_id' => $member->id, 'record_type' => $record->record_type,
            ]);

            return $this->load($record);
        });
    }

    public function update(StaffRecord $record, Hotel $hotel, User $actor, array $data): StaffRecord
    {
        return DB::transaction(function () use ($record, $hotel, $actor, $data) {
            $record = StaffRecord::query()->where('hotel_id', $hotel->id)->lockForUpdate()->findOrFail($record->id);
            $merged = [...$record->only(['user_id', 'record_type', 'start_date', 'end_date', 'leave_duration', 'amount', 'note']), ...$data];
            $member = $hotel->users()->whereKey($merged['user_id'])->firstOrFail();
            $role = $hotel->roles()->find($member->pivot->role_id);
            $this->validateLeaveRange($merged);
            $record->update([
                ...$this->normalized($merged), 'updated_by' => $actor->id,
                'staff_name_snapshot' => $member->name, 'role_name_snapshot' => $role?->name,
            ]);
            $this->audit->record($record, 'staff_record.updated', $actor, $hotel->id, null, ['record_type' => $record->record_type]);

            return $this->load($record);
        });
    }

    public function delete(StaffRecord $record, Hotel $hotel, User $actor): void
    {
        DB::transaction(function () use ($record, $hotel, $actor): void {
            $record = StaffRecord::query()->where('hotel_id', $hotel->id)->lockForUpdate()->findOrFail($record->id);
            $this->audit->record($record, 'staff_record.deleted', $actor, $hotel->id, null, [
                'staff_user_id' => $record->user_id, 'record_type' => $record->record_type,
            ]);
            $record->delete();
        });
    }

    public function load(StaffRecord $record): StaffRecord
    {
        return $record->fresh()->load(['user:id,name,email', 'creator:id,name', 'updater:id,name']);
    }

    private function normalized(array $data): array
    {
        $leave = $data['record_type'] === 'leave';

        return [
            'user_id' => $data['user_id'], 'record_type' => $data['record_type'],
            'start_date' => $data['start_date'], 'end_date' => $leave ? ($data['end_date'] ?? $data['start_date']) : null,
            'leave_duration' => $leave ? $data['leave_duration'] : null,
            'amount' => in_array($data['record_type'], ['advance_paid', 'extra_paid'], true) ? ($data['amount'] ?? null) : null,
            'note' => $data['note'] ?? null,
        ];
    }

    private function validateLeaveRange(array $data): void
    {
        if ($data['record_type'] !== 'leave') {
            return;
        }
        $start = CarbonImmutable::parse($data['start_date'])->toDateString();
        $end = CarbonImmutable::parse($data['end_date'] ?? $data['start_date'])->toDateString();
        if ($end !== $start && $data['leave_duration'] !== 'full_day') {
            throw ValidationException::withMessages(['leave_duration' => ['Multi-day leave must use Full day.']]);
        }
    }
}
