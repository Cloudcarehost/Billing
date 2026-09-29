<?php

namespace App\Http\Controllers\Api;

use App\Events\RestaurantUpdated;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Services\DiningBillingService;
use App\Services\TablePublicLinkService;
use App\Support\RestaurantRealtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\DiningSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiningTableController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return $this->success($this->tables($request)->get()->map(fn (DiningTable $table) => $this->present($table))->values());
    }

    public function status(Request $request): JsonResponse
    {
        $tables = $this->baseTables($request)->with('outlet:id,name,code')->orderBy('sort_order')->orderBy('name')->get();
        $tableIds = $tables->pluck('id');
        $sessions = DiningSession::query()
            ->whereIn('status', ['occupied', 'pending_bill'])
            ->where(function ($query) use ($tableIds) {
                $query->whereIn('dining_table_id', $tableIds)
                    ->orWhereHas('joinedTables', fn ($joined) => $joined->whereIn('dining_tables.id', $tableIds));
            })
            ->with('waiter:id,name', 'invoice', 'diningTable:id,name,code', 'joinedTables:id,name,code')
            ->get();
        $sessionsByTable = [];
        foreach ($sessions as $session) {
            foreach ($session->memberTableIds() as $tableId) {
                $sessionsByTable[$tableId] = $session;
            }
        }
        $sessionIds = $sessions->pluck('id');
        $progress = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.dining_session_id', $sessionIds)->groupBy('orders.dining_session_id')
            ->selectRaw("orders.dining_session_id, COUNT(*) active_item_count, SUM(CASE WHEN order_items.status = 'pending' THEN 1 ELSE 0 END) pending_count, SUM(CASE WHEN order_items.status = 'preparing' THEN 1 ELSE 0 END) preparing_count, SUM(CASE WHEN order_items.status = 'ready' THEN 1 ELSE 0 END) ready_count, SUM(CASE WHEN order_items.status = 'served' THEN 1 ELSE 0 END) served_count")
            ->get()->keyBy('dining_session_id');
        $previewRows = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.dining_session_id', $sessionIds)->where('order_items.status', '!=', 'cancelled')
            ->orderByDesc('order_items.id')
            ->get(['orders.dining_session_id', 'order_items.id', 'order_items.item_name', 'order_items.quantity', 'order_items.status']);
        $previews = $previewRows->groupBy('dining_session_id')->map(fn ($items) => $items->take(3));

        return $this->success($tables->map(function (DiningTable $table) use ($sessionsByTable, $progress, $previews) {
            $session = $sessionsByTable[$table->id] ?? null;
            $counts = $session ? $progress->get($session->id) : null;
            $displayStatus = ! $table->is_active ? 'available' : ($session?->status === 'pending_bill' ? 'pending_bill' : ($session ? (($counts?->served_count ?? 0) > 0 ? 'food_serving' : 'occupied') : 'available'));

            return [
                'id' => $table->id, 'outlet_id' => $table->outlet_id, 'name' => $table->name, 'code' => $table->code,
                'capacity' => $table->capacity, 'sort_order' => $table->sort_order, 'is_active' => $table->is_active,
                'service_type' => $table->service_type ?? 'dine_in',
                'waiter_called' => $table->waiter_called_at !== null,
                'outlet' => $table->outlet, 'display_status' => $displayStatus, 'current_total' => $session?->total_amount ?? '0.00',
                'primary_table' => $session?->diningTable ? ['id' => $session->diningTable->id, 'name' => $session->diningTable->name] : null,
                'joined_tables' => $session ? $session->joinedTables->map(fn ($joined) => ['id' => $joined->id, 'name' => $joined->name])->values() : [],
                'active_session' => $session ? [
                    'id' => $session->id, 'dining_table_id' => $session->dining_table_id, 'waiter_id' => $session->waiter_id,
                    'guest_count' => $session->guest_count, 'status' => $session->status, 'subtotal' => $session->subtotal,
                    'tax_amount' => $session->tax_amount, 'total_amount' => $session->total_amount, 'opened_at' => $session->opened_at,
                    'waiter' => $session->waiter, 'invoice' => $session->invoice,
                    'active_item_count' => (int) ($counts?->active_item_count ?? 0),
                    'kitchen_progress' => ['pending' => (int) ($counts?->pending_count ?? 0), 'preparing' => (int) ($counts?->preparing_count ?? 0), 'ready' => (int) ($counts?->ready_count ?? 0), 'served' => (int) ($counts?->served_count ?? 0)],
                    'preview_items' => ($previews->get($session->id) ?? collect())->map(fn ($item) => ['id' => $item->id, 'item_name' => $item->item_name, 'quantity' => $item->quantity, 'status' => $item->status])->values(),
                    'orders' => ($previews->get($session->id) ?? collect())->isEmpty() ? [] : [[
                        'id' => 0, 'ticket_number' => 'Current items', 'round_number' => 0, 'status' => 'preview',
                        'items' => ($previews->get($session->id) ?? collect())->map(fn ($item) => ['id' => $item->id, 'item_name' => $item->item_name, 'quantity' => $item->quantity, 'status' => $item->status])->values(),
                    ]],
                    'bill_requested' => $session->status === 'pending_bill',
                    'display_name' => $session->displayName(),
                    'joined_tables' => $session->joinedTables->map(fn ($joined) => ['id' => $joined->id, 'name' => $joined->name])->values(),
                    'primary_table' => $session->diningTable ? ['id' => $session->diningTable->id, 'name' => $session->diningTable->name] : null,
                ] : null,
            ];
        })->values());
    }

    public function store(Request $request, TablePublicLinkService $links): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $this->validated($request, $hotel);
        $table = DiningTable::query()->create($data);
        $links->createForTable($table);

        return $this->success($this->present($table->load('outlet:id,name,code', 'activeSession.orders.items')), 'Dining table created.', 201);
    }

    public function update(Request $request, int $table): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $table = $this->tables($request)->findOrFail($table);
        $data = $this->validated($request, $hotel, $table);
        if (($data['is_active'] ?? true) === false && ($table->activeSession()->exists() || DiningSession::occupying($table->id))) {
            abort(422, 'A table with an active session cannot be disabled.');
        }
        $table->update($data);

        return $this->success($this->present($table->fresh()->load('outlet:id,name,code', 'activeSession.orders.items')), 'Dining table updated.');
    }

    public function startParcel(Request $request, DiningBillingService $billing): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $outletId = $request->integer('outlet_id') ?: $request->attributes->get('currentOutletId');
        if (! $outletId) {
            throw ValidationException::withMessages(['outlet_id' => ['Select an outlet before starting a parcel order.']]);
        }

        $table = DB::transaction(function () use ($request, $hotel, $outletId, $billing) {
            Outlet::query()->where('hotel_id', $hotel->id)->whereIn('id', $request->attributes->get('accessibleOutletIds', []))->lockForUpdate()->findOrFail($outletId);

            $table = DiningTable::query()
                ->where('outlet_id', $outletId)
                ->where('service_type', 'parcel')
                ->where('is_active', true)
                ->whereDoesntHave('activeSession')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($table === null) {
                $next = DiningTable::query()->where('outlet_id', $outletId)->where('service_type', 'parcel')->lockForUpdate()->count() + 1;
                $table = DiningTable::query()->create([
                    'outlet_id' => $outletId,
                    'name' => "Parcel {$next}",
                    'code' => 'PARCEL-'.$next,
                    'capacity' => 1,
                    'sort_order' => 1000 + $next,
                    'is_active' => true,
                    'service_type' => 'parcel',
                ]);
            }

            $billing->openSession($table->load('outlet:id,hotel_id'), $request->user(), ['guest_count' => 1]);

            return $table;
        });

        return $this->success($this->present($table->fresh()->load('outlet:id,name,code', 'activeSession.orders.items', 'activeSession.waiter:id,name')), 'Parcel order opened.', 201);
    }

    public function acknowledgeWaiterCall(Request $request, int $table): JsonResponse
    {
        $diningTable = $this->baseTables($request)->with('outlet:id,hotel_id', 'activeSession')->findOrFail($table);
        if ($diningTable->waiter_called_at) {
            $diningTable->update(['waiter_called_at' => null]);
            $session = $diningTable->activeSession ?? DiningSession::occupying($diningTable->id);
            RestaurantUpdated::dispatch('waiter_call_cleared', $diningTable->outlet->hotel_id, $diningTable->outlet_id, $diningTable->id, $session?->id, null, null, $session?->waiter_id, null, RestaurantRealtime::table($diningTable, $session, [
                'table_name' => $diningTable->name,
                'waiter_called' => false,
            ]));
        }

        return $this->success(['waiter_called' => false]);
    }

    private function tables(Request $request)
    {
        return $this->baseTables($request)
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('outlet_id', $request->integer('outlet_id')))
            ->with('outlet:id,name,code', 'activeSession.diningTable:id,name,code,capacity', 'activeSession.waiter:id,name', 'activeSession.orders.items', 'activeSession.invoice')->orderBy('sort_order')->orderBy('name');
    }

    private function baseTables(Request $request)
    {
        $hotel = $request->attributes->get('currentHotel');

        return DiningTable::query()->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))
            ->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))
            ->when($request->filled('table_id'), fn ($q) => $q->whereKey($request->integer('table_id')))
            ->when($request->attributes->get('currentOutletId'), fn ($q, $id) => $q->where('outlet_id', $id));
    }

    private function validated(Request $request, $hotel, ?DiningTable $table = null): array
    {
        $data = $request->validate([
            'outlet_id' => [$table ? 'sometimes' : 'required', 'integer'], 'name' => [$table ? 'sometimes' : 'required', 'string', 'max:80'],
            'code' => [$table ? 'sometimes' : 'required', 'alpha_dash', 'max:30'], 'capacity' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean'],
            'service_type' => ['sometimes', Rule::in(['dine_in', 'parcel'])],
        ]);
        $outletId = $data['outlet_id'] ?? $table?->outlet_id;
        Outlet::query()->where('hotel_id', $hotel->id)->findOrFail($outletId);
        $data['code'] = $data['code'] ?? $table?->code;
        validator($data, ['code' => [Rule::unique('dining_tables', 'code')->where('outlet_id', $outletId)->ignore($table)]])->validate();

        return $data;
    }

    private function present(DiningTable $table): array
    {
        $session = $table->activeSession ?? DiningSession::occupying($table->id)?->load('orders.items', 'waiter:id,name', 'diningTable:id,name', 'joinedTables:id,name');
        $hasServedFood = $session?->orders->flatMap->items->contains(fn ($item) => $item->status === 'served') ?? false;
        $displayStatus = ! $table->is_active ? 'available' : ($session?->status === 'pending_bill' ? 'pending_bill' : ($session ? ($hasServedFood ? 'food_serving' : 'occupied') : 'available'));

        return ['id' => $table->id, 'outlet_id' => $table->outlet_id, 'name' => $table->name, 'code' => $table->code, 'capacity' => $table->capacity, 'sort_order' => $table->sort_order, 'is_active' => $table->is_active, 'service_type' => $table->service_type ?? 'dine_in', 'waiter_called' => $table->waiter_called_at !== null, 'outlet' => $table->outlet, 'display_status' => $displayStatus, 'primary_table' => $session?->diningTable ? ['id' => $session->diningTable->id, 'name' => $session->diningTable->name] : null, 'joined_tables' => $session ? $session->joinedTables?->map(fn ($joined) => ['id' => $joined->id, 'name' => $joined->name])->values() ?? [] : [], 'active_session' => $session, 'current_total' => $session?->total_amount ?? '0.00'];
    }
}
