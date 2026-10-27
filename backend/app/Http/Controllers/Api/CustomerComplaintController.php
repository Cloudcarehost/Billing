<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComplaintOutcome;
use App\Enums\ComplaintReason;
use App\Enums\ComplaintResolutionType;
use App\Enums\ComplaintResponsibilityArea;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintUserAttribution;
use App\Models\CustomerComplaint;
use App\Models\DiningSession;
use App\Models\Hotel;
use App\Models\Outlet;
use App\Services\CustomerComplaintReportService;
use App\Services\CustomerComplaintService;
use App\Support\HotelDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerComplaintController extends ApiController
{
    public function options(): JsonResponse
    {
        return $this->success([
            'statuses' => ComplaintStatus::values(),
            'reasons' => ComplaintReason::values(),
            'responsibility_areas' => ComplaintResponsibilityArea::values(),
            'outcomes' => ComplaintOutcome::values(),
            'resolution_types' => ComplaintResolutionType::values(),
            'user_attributions' => ComplaintUserAttribution::values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $query = $this->complaints($request)
            ->with([
                'outlet:id,name,code', 'reportedTable:id,name,code', 'creator:id,name', 'resolver:id,name',
                'items', 'involvedUsers.user:id,name',
            ])
            ->when($filters['from'] ?? null, fn ($builder, $from) => $builder->whereDate('business_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($builder, $to) => $builder->whereDate('business_date', '<=', $to))
            ->when($filters['outlet_id'] ?? null, fn ($builder, $id) => $builder->where('outlet_id', $id))
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['reason_code'] ?? null, fn ($builder, $reason) => $builder->where('reason_code', $reason))
            ->when($filters['outcome'] ?? null, fn ($builder, $outcome) => $builder->where('outcome', $outcome))
            ->when($filters['search'] ?? null, function ($builder, string $search): void {
                $builder->where(function ($nested) use ($search): void {
                    $like = "%{$search}%";
                    $nested->where('complaint_number', 'like', $like)
                        ->orWhere('table_name_snapshot', 'like', $like)
                        ->orWhere('invoice_number_snapshot', 'like', $like)
                        ->orWhere('customer_name_snapshot', 'like', $like)
                        ->orWhereHas('items', fn ($items) => $items->where('item_name_snapshot', 'like', $like)->orWhere('ticket_number_snapshot', 'like', $like))
                        ->orWhereHas('involvedUsers', fn ($users) => $users->where('user_name_snapshot', 'like', $like));
                });
            })
            ->orderByDesc('reported_at')
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage));
    }

    public function overview(Request $request, CustomerComplaintReportService $reports): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $filters = $this->filters($request, true);

        return $this->success($reports->overview($hotel, $filters));
    }

    public function sources(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'session_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $outletId = $this->outletId($request, isset($data['outlet_id']) ? (int) $data['outlet_id'] : null);
        $search = trim((string) ($data['search'] ?? ''));
        $sessionId = isset($data['session_id']) ? (int) $data['session_id'] : null;
        $sourceDate = $data['date'] ?? null;
        $timezone = $hotel->timezone ?: config('app.timezone');
        $dateStart = $sourceDate ? CarbonImmutable::parse($sourceDate, $timezone)->startOfDay()->utc() : null;
        $dateEnd = $sourceDate ? CarbonImmutable::parse($sourceDate, $timezone)->endOfDay()->utc() : null;

        $sessions = DiningSession::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
            ->when($sessionId, fn ($query) => $query->whereKey($sessionId), fn ($query) => $query->where(function ($scope): void {
                $scope->whereIn('status', ['occupied', 'pending_bill'])->orWhere('opened_at', '>=', now()->subDays(90));
            }))
            ->when($sourceDate, function ($query) use ($sourceDate, $dateStart, $dateEnd): void {
                $query->where(function ($dated) use ($sourceDate, $dateStart, $dateEnd): void {
                    $dated->whereHas('invoice', fn ($invoice) => $invoice->whereDate('business_date', $sourceDate))
                        ->orWhere(function ($unbilled) use ($dateStart, $dateEnd): void {
                            $unbilled->whereDoesntHave('invoice')->whereBetween('opened_at', [$dateStart, $dateEnd]);
                        });
                });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $like = "%{$search}%";
                $query->where(function ($nested) use ($like): void {
                    $nested->whereHas('diningTable', fn ($table) => $table->where('name', 'like', $like)->orWhere('code', 'like', $like))
                        ->orWhereHas('orders', fn ($orders) => $orders->where('ticket_number', 'like', $like))
                        ->orWhereHas('invoice', fn ($invoice) => $invoice->where('invoice_number', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('customer_phone', 'like', $like))
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $like)->orWhere('phone', 'like', $like));
                });
            })
            ->with([
                'outlet:id,name,code', 'diningTable:id,name,code', 'joinedTables:id,name,code',
                'customer:id,name,phone',
                'invoice' => fn ($invoices) => $invoices->select([
                    'invoices.id', 'invoices.dining_session_id', 'invoices.invoice_number', 'invoices.status',
                    'invoices.customer_name', 'invoices.customer_phone', 'invoices.business_date',
                ]),
                'orders' => fn ($orders) => $orders->orderBy('round_number')->select('id', 'dining_session_id', 'ticket_number', 'round_number', 'sent_at'),
                'orders.items' => fn ($items) => $items->where('status', '!=', 'cancelled')->orderBy('id')->select('id', 'order_id', 'product_id', 'item_name', 'quantity', 'status', 'service_route', 'served_at', 'tracked_done_at'),
            ])
            ->orderByRaw("CASE WHEN status IN ('occupied', 'pending_bill') THEN 0 ELSE 1 END")
            ->orderByDesc('opened_at')
            ->limit((int) ($data['limit'] ?? 25))
            ->get();

        return $this->success($sessions->map(fn (DiningSession $session) => [
            'id' => $session->id,
            'outlet' => $session->outlet,
            'status' => $session->status,
            'table' => $session->diningTable,
            'joined_tables' => $session->joinedTables,
            'display_name' => $session->displayName(),
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'customer' => $session->customer,
            'invoice' => $session->invoice,
            'orders' => $session->orders->map(fn ($order) => [
                'id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'round_number' => $order->round_number,
                'sent_at' => $order->sent_at,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'status' => $item->status,
                    'service_route' => $item->service_route,
                    'served_at' => $item->served_at,
                    'tracked_done_at' => $item->tracked_done_at,
                    'delivery_evidence' => $item->served_at ? 'served' : ($session->invoice ? 'billed' : 'staff_confirmation_required'),
                ])->values(),
            ])->values(),
        ])->values());
    }

    public function staff(Request $request, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['outlet_id' => ['nullable', 'integer']]);
        $outletId = $this->outletId($request, isset($data['outlet_id']) ? (int) $data['outlet_id'] : null);
        if (! $outletId) {
            throw ValidationException::withMessages(['outlet_id' => ['Select an outlet to load its staff.']]);
        }

        return $this->success($service->staff($hotel, $outletId));
    }

    public function show(Request $request, int $complaint, CustomerComplaintService $service): JsonResponse
    {
        return $this->success($service->load($this->complaints($request)->findOrFail($complaint)));
    }

    public function store(Request $request, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'dining_session_id' => ['required', 'integer'],
            'reported_table_id' => ['nullable', 'integer'],
            'reason_code' => ['required', Rule::enum(ComplaintReason::class)],
            'customer_statement' => ['required', 'string', 'min:3', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'responsibility_area' => ['nullable', Rule::enum(ComplaintResponsibilityArea::class)],
            'reported_at' => ['nullable', 'date', 'before_or_equal:now'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'users' => ['sometimes', 'array', 'max:50'],
            'users.*.user_id' => ['required', 'integer', 'distinct'],
            'users.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success($service->create($hotel, $request->user(), $data, $request->attributes->get('accessibleOutletIds', [])), 'Customer complaint registered.', 201);
    }

    public function update(Request $request, int $complaint, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([ComplaintStatus::Open->value, ComplaintStatus::UnderReview->value])],
            'reason_code' => ['sometimes', Rule::enum(ComplaintReason::class)],
            'customer_statement' => ['sometimes', 'string', 'min:3', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'responsibility_area' => ['nullable', Rule::enum(ComplaintResponsibilityArea::class)],
            'change_note' => ['nullable', 'string', 'max:2000'],
            'users' => ['sometimes', 'array', 'max:50'],
            'users.*.user_id' => ['required', 'integer', 'distinct'],
            'users.*.attribution' => ['required', Rule::enum(ComplaintUserAttribution::class)],
            'users.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success($service->update($this->complaints($request)->findOrFail($complaint), $hotel, $request->user(), $data), 'Complaint updated.');
    }

    public function addEvent(Request $request, int $complaint, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['note' => ['required', 'string', 'min:1', 'max:5000']]);

        return $this->success($service->addNote($this->complaints($request)->findOrFail($complaint), $hotel, $request->user(), $data['note']), 'Complaint note added.');
    }

    public function resolve(Request $request, int $complaint, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'outcome' => ['required', Rule::enum(ComplaintOutcome::class)],
            'responsibility_area' => ['required', Rule::enum(ComplaintResponsibilityArea::class)],
            'resolution_type' => ['required', Rule::enum(ComplaintResolutionType::class)],
            'resolution_note' => ['required', 'string', 'min:3', 'max:5000'],
            'users' => ['sometimes', 'array', 'max:50'],
            'users.*.user_id' => ['required', 'integer', 'distinct'],
            'users.*.attribution' => ['required', Rule::enum(ComplaintUserAttribution::class)],
            'users.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success($service->resolve($this->complaints($request)->findOrFail($complaint), $hotel, $request->user(), $data), 'Complaint resolved.');
    }

    public function dismiss(Request $request, int $complaint, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'outcome' => ['required', Rule::in([ComplaintOutcome::CustomerMisunderstanding->value, ComplaintOutcome::Inconclusive->value])],
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        return $this->success($service->dismiss($this->complaints($request)->findOrFail($complaint), $hotel, $request->user(), $data), 'Complaint dismissed.');
    }

    public function reopen(Request $request, int $complaint, CustomerComplaintService $service): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:5000']]);

        return $this->success($service->reopen($this->complaints($request)->findOrFail($complaint), $hotel, $request->user(), $data['reason']), 'Complaint reopened.');
    }

    private function complaints(Request $request)
    {
        return CustomerComplaint::query()
            ->where('hotel_id', $request->attributes->get('currentHotel')->id)
            ->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))
            ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id));
    }

    private function filters(Request $request, bool $withDefaults = false): array
    {
        /** @var Hotel $hotel */
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'yesterday', 'last_7', 'week', 'month', 'last_month', 'year'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ComplaintStatus::class)],
            'reason_code' => ['nullable', Rule::enum(ComplaintReason::class)],
            'outcome' => ['nullable', Rule::enum(ComplaintOutcome::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        if (! empty($data['period']) && empty($data['from'])) {
            [$data['from'], $data['to']] = HotelDate::rangeForPeriod($hotel, $data['period']);
        }
        if ($withDefaults) {
            [$defaultFrom, $defaultTo] = HotelDate::rangeForPeriod($hotel, 'month');
            $data['from'] ??= $defaultFrom;
            $data['to'] ??= $defaultTo;
        }
        if (isset($data['outlet_id'])) {
            $data['outlet_id'] = $this->outletId($request, (int) $data['outlet_id']);
        } elseif ($request->attributes->get('currentOutletId')) {
            $data['outlet_id'] = (int) $request->attributes->get('currentOutletId');
        }
        if (isset($data['search'])) {
            $data['search'] = trim($data['search']);
        }

        return $data;
    }

    private function outletId(Request $request, ?int $requested): ?int
    {
        $outletId = $requested ?? $request->attributes->get('currentOutletId');
        if ($outletId === null) {
            return null;
        }
        Outlet::query()
            ->where('hotel_id', $request->attributes->get('currentHotel')->id)
            ->whereIn('id', $request->attributes->get('accessibleOutletIds', []))
            ->findOrFail($outletId);

        return (int) $outletId;
    }
}
