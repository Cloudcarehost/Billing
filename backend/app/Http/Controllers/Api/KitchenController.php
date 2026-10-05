<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderItemStatus;
use App\Enums\OutletOrderFlow;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\KitchenService;
use App\Services\OrderService;
use App\Support\RestaurantRealtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KitchenController extends ApiController
{
    public function queue(Request $request, int $station): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $station = KitchenStation::query()->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))->findOrFail($station);
        $status = $request->validate(['status' => ['nullable', 'in:pending,preparing,ready']])['status'] ?? null;
        $limit = min(max($request->integer('per_status', 50), 1), 100);
        $base = OrderItem::query()
            ->where('fulfillment_mode', 'kitchen')
            ->where('kitchen_station_id', $station->id)
            ->with('order.diningSession.diningTable', 'order.creator:id,name');

        $queries = collect($status ? [$status] : ['pending', 'preparing', 'ready'])->mapWithKeys(function (string $itemStatus) use ($base, $limit) {
            $query = (clone $base)->where('status', $itemStatus);
            if ($itemStatus === 'ready') {
                $query->where('ready_at', '>=', now()->subDay())->orderByDesc('ready_at')->orderByDesc('id');
            } elseif ($itemStatus === 'preparing') {
                $query->orderBy('preparing_at')->orderBy('created_at')->orderBy('id');
            } else {
                $query->orderBy('created_at')->orderBy('id');
            }

            return [$itemStatus => $query->limit($limit)->get()];
        });
        $items = $queries->values()->flatten(1)->values();

        return $this->success([
            'station' => $station,
            'items' => $items,
            'meta' => ['per_status' => $limit, 'ready_since' => now()->subDay()->toIso8601String()],
        ]);
    }

    public function transition(Request $request, int $item, KitchenService $kitchen, InventoryService $inventory, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([OrderItemStatus::Preparing->value, OrderItemStatus::Ready->value])]]);
        $item = $this->item($request, $item);

        return $this->success($kitchen->transition($item, $data['status'], $request->user(), $inventory, $audit), 'Kitchen status updated.');
    }

    public function serve(Request $request, int $item, KitchenService $kitchen, InventoryService $inventory, AuditService $audit): JsonResponse
    {
        $item = $this->item($request, $item);

        return $this->success($kitchen->transition($item, 'served', $request->user(), $inventory, $audit), 'Item served.');
    }

    public function cancel(Request $request, int $item, OrderService $orders, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999']]);
        $item = $this->item($request, $item);
        $permissions = $request->attributes->get('currentPermissions', []);
        $owner = (bool) $request->attributes->get('currentRole')?->is_owner;
        $cancelQty = array_key_exists('quantity', $data) && $data['quantity'] !== null ? (float) $data['quantity'] : (float) $item->quantity;
        abort_unless($cancelQty <= (float) $item->quantity, 422, 'Enter a quantity that is still on this line.');

        $preparedKitchenItem = $item->fulfillment_mode === 'kitchen' && in_array($item->status, ['preparing', 'ready'], true);
        if ($preparedKitchenItem) {
            abort_unless($owner || in_array('orders.cancel_prepared', $permissions, true), 403, 'Cancelling a prepared item requires additional permission.');
        }
        if ($item->status === 'served') {
            abort_unless($owner || in_array('orders.cancel_served', $permissions, true), 403, 'Cancelling a served item requires additional permission.');
        }
        $cancelShare = (float) $item->quantity > 0 ? ((float) $item->line_total / (float) $item->quantity) * $cancelQty : (float) $item->line_total;
        if ($cancelShare >= (float) config('restaurant.high_value_cancellation_threshold', 1000)) {
            abort_unless($owner || in_array('orders.cancel_high_value', $permissions, true), 403, 'This high-value cancellation requires approval.');
        }

        return $this->success($orders->cancelItem($item, $data['reason'], $request->user(), $inventory, array_key_exists('quantity', $data) ? (float) $data['quantity'] : null), 'Item cancelled.');
    }

    public function liveBoard(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $outletIds = $request->attributes->get('accessibleOutletIds', []);
        $outletId = $request->integer('outlet_id') ?: (int) ($outletIds[0] ?? 0);
        $outlet = Outlet::query()->where('hotel_id', $hotel->id)->whereIn('id', $outletIds)->findOrFail($outletId);
        if ($outlet->order_flow !== OutletOrderFlow::DirectBill->value) {
            return $this->success(['queue' => [], 'done' => []]);
        }

        $cutoff = now()->subHours(3);
        $base = Order::query()
            ->whereHas('diningSession', fn ($query) => $query->where('hotel_id', $hotel->id)->where('outlet_id', $outlet->id)->whereIn('status', ['occupied', 'pending_bill']))
            ->whereHas('items', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with(['items' => fn ($query) => $query->where('status', '!=', 'cancelled'), 'diningSession.diningTable:id,name,code', 'diningSession.joinedTables:id,name', 'creator:id,name']);

        $queue = (clone $base)->whereNull('tracked_done_at')->orderBy('sent_at')->orderBy('id')->limit(100)->get();
        $done = (clone $base)->whereNotNull('tracked_done_at')->where('tracked_done_at', '>=', $cutoff)->orderByDesc('tracked_done_at')->orderByDesc('id')->limit(100)->get();

        return $this->success([
            'queue' => $queue->map(fn (Order $order) => RestaurantRealtime::liveTicket($order))->values(),
            'done' => $done->map(fn (Order $order) => RestaurantRealtime::liveTicket($order))->values(),
        ]);
    }

    public function trackDone(Request $request, int $order): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $order = Order::query()
            ->whereHas('diningSession', fn ($query) => $query
                ->where('hotel_id', $hotel->id)
                ->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))
                ->whereIn('status', ['occupied', 'pending_bill']))
            ->with(['items', 'diningSession.outlet', 'diningSession.diningTable', 'diningSession.joinedTables:id,name', 'creator:id,name'])
            ->findOrFail($order);
        abort_unless($order->diningSession?->outlet?->order_flow === OutletOrderFlow::DirectBill->value, 422, 'Live tracking is only for direct to bill outlets.');
        if (! $order->tracked_done_at) {
            $order->update(['tracked_done_at' => now()]);
        }
        $ticket = RestaurantRealtime::liveTicket($order->fresh(['items', 'diningSession.diningTable', 'diningSession.joinedTables:id,name', 'creator:id,name']));
        RestaurantRealtime::dispatchToMembers('live_ticket_done', $order->diningSession, ['live_ticket' => $ticket], $order->id);

        return $this->success($ticket, 'Ticket marked done.');
    }

    private function item(Request $request, int $id): OrderItem
    {
        $hotel = $request->attributes->get('currentHotel');

        return OrderItem::query()->whereHas('order.diningSession', fn ($q) => $q->where('hotel_id', $hotel->id)->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', [])))->findOrFail($id);
    }
}
