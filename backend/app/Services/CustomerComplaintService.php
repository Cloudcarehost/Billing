<?php

namespace App\Services;

use App\Enums\ComplaintOutcome;
use App\Enums\ComplaintResolutionType;
use App\Enums\ComplaintResponsibilityArea;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintUserAttribution;
use App\Enums\OrderItemStatus;
use App\Models\CustomerComplaint;
use App\Models\DiningSession;
use App\Models\Hotel;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use App\Support\HotelDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerComplaintService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param list<int> $accessibleOutletIds */
    public function create(Hotel $hotel, User $actor, array $data, array $accessibleOutletIds): CustomerComplaint
    {
        return DB::transaction(function () use ($hotel, $actor, $data, $accessibleOutletIds) {
            $session = DiningSession::query()
                ->where('hotel_id', $hotel->id)
                ->whereIn('outlet_id', $accessibleOutletIds)
                ->with([
                    'diningTable:id,name,code', 'joinedTables:id,name,code', 'customer:id,name',
                    'invoice' => fn ($invoices) => $invoices->select([
                        'invoices.id', 'invoices.dining_session_id', 'invoices.invoice_number',
                        'invoices.status', 'invoices.customer_name',
                    ]),
                ])
                ->lockForUpdate()
                ->findOrFail($data['dining_session_id']);

            $requestedItems = collect($data['items'])->keyBy(fn (array $item) => (int) $item['order_item_id']);
            $items = OrderItem::query()
                ->whereIn('id', $requestedItems->keys())
                ->whereHas('order', fn ($query) => $query->where('dining_session_id', $session->id))
                ->with('order:id,dining_session_id,ticket_number')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->count() !== $requestedItems->count()) {
                throw ValidationException::withMessages(['items' => ['Every selected item must belong to the chosen dining session.']]);
            }
            if ($items->contains(fn (OrderItem $item) => $item->status === OrderItemStatus::Cancelled->value)) {
                throw ValidationException::withMessages(['items' => ['Cancelled items cannot be attached to a customer complaint.']]);
            }

            foreach ($items as $item) {
                $complained = (float) $requestedItems->get($item->id)['quantity'];
                if ($complained <= 0 || $complained > (float) $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["The complained quantity for {$item->item_name} must be greater than zero and no more than the ordered quantity."],
                    ]);
                }
            }

            $reportedTableId = isset($data['reported_table_id']) ? (int) $data['reported_table_id'] : $session->dining_table_id;
            if (! in_array($reportedTableId, $session->memberTableIds(), true)) {
                throw ValidationException::withMessages(['reported_table_id' => ['The selected table does not belong to this dining session.']]);
            }
            $reportedTable = collect([$session->diningTable])->merge($session->joinedTables)->firstWhere('id', $reportedTableId);
            $reportedAt = CarbonImmutable::parse($data['reported_at'] ?? 'now', $hotel->timezone ?: config('app.timezone'));
            $invoice = $session->invoice;

            $complaint = CustomerComplaint::query()->create([
                'hotel_id' => $hotel->id,
                'outlet_id' => $session->outlet_id,
                'dining_session_id' => $session->id,
                'invoice_id' => $invoice?->id,
                'customer_id' => $session->customer_id,
                'reported_table_id' => $reportedTableId,
                'created_by' => $actor->id,
                'complaint_number' => 'CMP-'.str_replace('-', '', HotelDate::businessDate($hotel, $reportedAt)).'-'.Str::upper((string) Str::ulid()),
                'business_date' => HotelDate::businessDate($hotel, $reportedAt),
                'status' => ComplaintStatus::Open->value,
                'reason_code' => $data['reason_code'],
                'customer_statement' => $data['customer_statement'],
                'internal_note' => $data['internal_note'] ?? null,
                'responsibility_area' => $data['responsibility_area'] ?? null,
                'table_name_snapshot' => $reportedTable?->name ?? $session->displayName(),
                'invoice_number_snapshot' => $invoice?->invoice_number,
                'customer_name_snapshot' => $session->customer?->name ?? $invoice?->customer_name,
                'reported_at' => $reportedAt,
            ]);

            foreach ($items as $item) {
                $complaint->items()->create([
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'ticket_number_snapshot' => $item->order?->ticket_number ?? 'Order',
                    'item_name_snapshot' => $item->item_name,
                    'item_status_snapshot' => $item->status,
                    'service_route_snapshot' => $item->service_route,
                    'ordered_quantity' => $item->quantity,
                    'complained_quantity' => $requestedItems->get($item->id)['quantity'],
                ]);
            }

            $this->replaceInvolvedUsers(
                $complaint,
                $hotel,
                $session->outlet_id,
                collect($data['users'] ?? [])->map(fn (array $entry) => [
                    'user_id' => (int) $entry['user_id'],
                    'attribution' => ComplaintUserAttribution::Mentioned->value,
                    'note' => $entry['note'] ?? null,
                ])->all(),
            );

            $complaint->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'created',
                'note' => $data['internal_note'] ?? null,
                'metadata' => [
                    'reason_code' => $complaint->reason_code,
                    'item_ids' => $items->pluck('id')->all(),
                    'mentioned_user_ids' => collect($data['users'] ?? [])->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                ],
                'occurred_at' => now(),
            ]);
            $this->audit->record($complaint, 'complaint.created', $actor, $hotel->id, $complaint->outlet_id, [
                'complaint_number' => $complaint->complaint_number,
                'reason_code' => $complaint->reason_code,
                'item_ids' => $items->pluck('id')->all(),
            ]);

            return $this->load($complaint);
        });
    }

    public function update(CustomerComplaint $complaint, Hotel $hotel, User $actor, array $data): CustomerComplaint
    {
        return DB::transaction(function () use ($complaint, $hotel, $actor, $data) {
            $complaint = CustomerComplaint::query()->lockForUpdate()->findOrFail($complaint->id);
            if (! in_array($complaint->status, [ComplaintStatus::Open->value, ComplaintStatus::UnderReview->value], true)) {
                throw ValidationException::withMessages(['status' => ['Reopen this complaint before editing its investigation details.']]);
            }

            $fields = collect($data)->only([
                'status', 'reason_code', 'customer_statement', 'internal_note', 'responsibility_area',
            ])->all();
            if ($fields !== []) {
                $complaint->fill($fields)->save();
            }
            if (array_key_exists('users', $data)) {
                $this->replaceInvolvedUsers($complaint, $hotel, $complaint->outlet_id, $data['users']);
            }

            $complaint->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'updated',
                'note' => $data['change_note'] ?? null,
                'metadata' => ['changed_fields' => array_keys($fields), 'staff_updated' => array_key_exists('users', $data)],
                'occurred_at' => now(),
            ]);
            $this->audit->record($complaint, 'complaint.updated', $actor, $hotel->id, $complaint->outlet_id, [
                'changed_fields' => array_keys($fields),
                'staff_updated' => array_key_exists('users', $data),
            ]);

            return $this->load($complaint);
        });
    }

    public function addNote(CustomerComplaint $complaint, Hotel $hotel, User $actor, string $note): CustomerComplaint
    {
        return DB::transaction(function () use ($complaint, $hotel, $actor, $note) {
            $complaint = CustomerComplaint::query()->lockForUpdate()->findOrFail($complaint->id);
            $complaint->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'note_added',
                'note' => $note,
                'occurred_at' => now(),
            ]);
            $this->audit->record($complaint, 'complaint.note_added', $actor, $hotel->id, $complaint->outlet_id);

            return $this->load($complaint);
        });
    }

    public function resolve(CustomerComplaint $complaint, Hotel $hotel, User $actor, array $data): CustomerComplaint
    {
        return DB::transaction(function () use ($complaint, $hotel, $actor, $data) {
            $complaint = CustomerComplaint::query()->lockForUpdate()->findOrFail($complaint->id);
            if ($complaint->status === ComplaintStatus::Resolved->value) {
                return $this->load($complaint);
            }
            if ($complaint->status === ComplaintStatus::Dismissed->value) {
                throw ValidationException::withMessages(['status' => ['Reopen this dismissed complaint before resolving it.']]);
            }

            $complaint->update([
                'status' => ComplaintStatus::Resolved->value,
                'outcome' => $data['outcome'],
                'responsibility_area' => $data['responsibility_area'],
                'resolution_type' => $data['resolution_type'],
                'resolution_note' => $data['resolution_note'],
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            if (array_key_exists('users', $data)) {
                $this->replaceInvolvedUsers($complaint, $hotel, $complaint->outlet_id, $data['users']);
            }
            $complaint->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'resolved',
                'note' => $data['resolution_note'],
                'metadata' => collect($data)->only(['outcome', 'responsibility_area', 'resolution_type'])->all(),
                'occurred_at' => now(),
            ]);
            $this->audit->record($complaint, 'complaint.resolved', $actor, $hotel->id, $complaint->outlet_id, [
                'outcome' => $complaint->outcome,
                'responsibility_area' => $complaint->responsibility_area,
                'resolution_type' => $complaint->resolution_type,
            ]);

            return $this->load($complaint);
        });
    }

    public function dismiss(CustomerComplaint $complaint, Hotel $hotel, User $actor, array $data): CustomerComplaint
    {
        return DB::transaction(function () use ($complaint, $hotel, $actor, $data) {
            $complaint = CustomerComplaint::query()->lockForUpdate()->findOrFail($complaint->id);
            if ($complaint->status === ComplaintStatus::Dismissed->value) {
                return $this->load($complaint);
            }
            if ($complaint->status === ComplaintStatus::Resolved->value) {
                throw ValidationException::withMessages(['status' => ['Reopen this resolved complaint before dismissing it.']]);
            }
            $complaint->update([
                'status' => ComplaintStatus::Dismissed->value,
                'outcome' => $data['outcome'],
                'responsibility_area' => $data['outcome'] === ComplaintOutcome::CustomerMisunderstanding->value
                    ? ComplaintResponsibilityArea::Customer->value
                    : ComplaintResponsibilityArea::Unknown->value,
                'resolution_type' => ComplaintResolutionType::NoActionRequired->value,
                'resolution_note' => $data['reason'],
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $complaint->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'dismissed',
                'note' => $data['reason'],
                'metadata' => ['outcome' => $data['outcome']],
                'occurred_at' => now(),
            ]);
            $this->audit->record($complaint, 'complaint.dismissed', $actor, $hotel->id, $complaint->outlet_id, ['outcome' => $complaint->outcome]);

            return $this->load($complaint);
        });
    }

    public function reopen(CustomerComplaint $complaint, Hotel $hotel, User $actor, string $reason): CustomerComplaint
    {
        return DB::transaction(function () use ($complaint, $hotel, $actor, $reason) {
            $complaint = CustomerComplaint::query()->lockForUpdate()->findOrFail($complaint->id);
            if (! in_array($complaint->status, [ComplaintStatus::Resolved->value, ComplaintStatus::Dismissed->value], true)) {
                throw ValidationException::withMessages(['status' => ['Only resolved or dismissed complaints can be reopened.']]);
            }
            $previous = collect($complaint->only(['status', 'outcome', 'responsibility_area', 'resolution_type', 'resolution_note', 'resolved_by', 'resolved_at']))->all();
            $complaint->update([
                'status' => ComplaintStatus::UnderReview->value,
                'outcome' => null,
                'resolution_type' => null,
                'resolution_note' => null,
                'resolved_by' => null,
                'resolved_at' => null,
            ]);
            $complaint->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'reopened',
                'note' => $reason,
                'metadata' => ['previous_resolution' => $previous],
                'occurred_at' => now(),
            ]);
            $this->audit->record($complaint, 'complaint.reopened', $actor, $hotel->id, $complaint->outlet_id, ['previous_status' => $previous['status']]);

            return $this->load($complaint);
        });
    }

    /** @return Collection<int, array{id: int, name: string, role: string, role_slug: string}> */
    public function staff(Hotel $hotel, int $outletId): Collection
    {
        $roles = $hotel->roles()->with('permissions:id,code')->get()->keyBy('id');

        return $hotel->users()
            ->wherePivot('is_active', true)
            ->with('accessibleOutlets:id')
            ->orderBy('users.name')
            ->get()
            ->filter(fn (User $user) => $this->memberCanAccessOutlet($user, $roles->get($user->pivot->role_id), $outletId))
            ->map(function (User $user) use ($roles) {
                $role = $roles->get($user->pivot->role_id);

                return ['id' => $user->id, 'name' => $user->name, 'role' => $role?->name ?? 'Staff', 'role_slug' => $role?->slug ?? 'staff'];
            })
            ->values();
    }

    public function load(CustomerComplaint $complaint): CustomerComplaint
    {
        return $complaint->fresh()->load([
            'outlet:id,name,code', 'reportedTable:id,name,code', 'creator:id,name', 'resolver:id,name',
            'customer:id,name,phone', 'invoice:id,dining_session_id,invoice_number,status,business_date',
            'diningSession:id,outlet_id,dining_table_id,status,opened_at,closed_at',
            'items', 'involvedUsers.user:id,name', 'events.actor:id,name',
        ]);
    }

    private function replaceInvolvedUsers(CustomerComplaint $complaint, Hotel $hotel, int $outletId, array $entries): void
    {
        $entries = collect($entries)->values();
        $ids = $entries->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->count() !== $entries->count()) {
            throw ValidationException::withMessages(['users' => ['Each staff member can be selected only once.']]);
        }
        $available = $this->staff($hotel, $outletId)->keyBy('id');
        if ($ids->diff($available->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['users' => ['Every selected staff member must be an active hotel member with access to this outlet.']]);
        }

        $complaint->involvedUsers()->delete();
        foreach ($entries as $entry) {
            $member = $available->get((int) $entry['user_id']);
            $complaint->involvedUsers()->create([
                'user_id' => $member['id'],
                'user_name_snapshot' => $member['name'],
                'role_name_snapshot' => $member['role'],
                'attribution' => $entry['attribution'] ?? ComplaintUserAttribution::Mentioned->value,
                'note' => $entry['note'] ?? null,
            ]);
        }
    }

    private function memberCanAccessOutlet(User $user, ?Role $role, int $outletId): bool
    {
        if ($role === null) {
            return false;
        }
        if ($role->is_owner || $role->permissions->contains('code', 'outlets.all')) {
            return true;
        }

        return $user->accessibleOutlets->contains('id', $outletId);
    }
}
