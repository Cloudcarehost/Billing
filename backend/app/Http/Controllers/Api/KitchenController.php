<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderItemStatus;
use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\KitchenService;
use App\Services\OrderService;
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
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $item = $this->item($request, $item);
        $permissions = $request->attributes->get('currentPermissions', []);
        $owner = (bool) $request->attributes->get('currentRole')?->is_owner;

        $preparedKitchenItem = $item->fulfillment_mode === 'kitchen' && in_array($item->status, ['preparing', 'ready'], true);
        if ($preparedKitchenItem) {
            abort_unless($owner || in_array('orders.cancel_prepared', $permissions, true), 403, 'Cancelling a prepared item requires additional permission.');
        }
        if ($item->status === 'served') {
            abort_unless($owner || in_array('orders.cancel_served', $permissions, true), 403, 'Cancelling a served item requires additional permission.');
        }
        if ((float) $item->line_total >= (float) config('restaurant.high_value_cancellation_threshold', 1000)) {
            abort_unless($owner || in_array('orders.cancel_high_value', $permissions, true), 403, 'This high-value cancellation requires approval.');
        }

        return $this->success($orders->cancelItem($item, $data['reason'], $request->user(), $inventory), 'Item cancelled.');
    }

    private function item(Request $request, int $id): OrderItem
    {
        $hotel = $request->attributes->get('currentHotel');

        return OrderItem::query()->whereHas('order.diningSession', fn ($q) => $q->where('hotel_id', $hotel->id)->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', [])))->findOrFail($id);
    }
}
