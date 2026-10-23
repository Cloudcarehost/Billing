<?php

namespace App\Services;

use App\Enums\FulfillmentMode;
use App\Enums\OrderItemStatus;
use App\Enums\OutletOrderFlow;
use App\Models\DiningSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DiningSessionGuard;
use App\Support\RestaurantRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KitchenService
{
    public function __construct(private readonly OrderService $orders) {}

    public function transition(OrderItem $item, string $status, User $user, InventoryService $inventory, AuditService $audit): OrderItem
    {
        $push = null;
        $item = DB::transaction(function () use ($item, $status, $user, $inventory, $audit, &$push) {
            $item = OrderItem::query()->with(['order.diningSession.hotel', 'order.diningSession.diningTable', 'order.diningSession.invoice', 'product'])->lockForUpdate()->findOrFail($item->id);
            DiningSessionGuard::assertNotClosedOrInvoiced($item->order->diningSession);
            $allowed = $item->fulfillment_mode === 'direct'
                ? ['ready' => ['served']]
                : ['pending' => ['preparing'], 'preparing' => ['ready'], 'ready' => ['served']];
            if (! in_array($status, $allowed[$item->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['Invalid item status transition.']]);
            } $fields = ['status' => $status];
            if ($status === 'preparing') {
                $fields += ['preparing_at' => now(), 'preparing_by' => $user->id];
                if (! $item->inventory_deducted && $item->order->diningSession->hotel->inventory_deduction_rule === 'preparing') {
                    $inventory->deductForItem($item, $user);
                    $fields['inventory_deducted'] = true;
                }
            } if ($status === 'ready') {
                $fields += ['ready_at' => now(), 'ready_by' => $user->id];
            } if ($status === 'served') {
                $fields += ['served_at' => now(), 'served_by' => $user->id];
                if (! $item->inventory_deducted && $item->order->diningSession->hotel->inventory_deduction_rule === 'served') {
                    $inventory->deductForItem($item, $user);
                    $fields['inventory_deducted'] = true;
                }
            } $item->update($fields);
            $this->orders->synchronizeStatus($item->order);
            $session = $item->order->diningSession;
            $audit->record($item, "order_item.{$status}", $user, $session->hotel_id, $session->outlet_id);
            $session->load(['diningTable', 'waiter', 'joinedTables:id,name', 'orders.items']);
            RestaurantRealtime::dispatchToMembers("item_{$status}", $session, [
                'status' => $status,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'kitchen_item' => RestaurantRealtime::kitchenItem($item->fresh()),
            ], $item->order_id, $item->id, $item->kitchen_station_id);
            if ($status === 'ready' && $session->waiter_id) {
                $push = [$session->waiter_id, [
                    'title' => ($session->displayName()).': order ready',
                    'body' => trim($item->quantity.' × '.$item->item_name).' is ready to serve.',
                    'tag' => 'ready-item-'.$item->id,
                    'url' => '/app/orders?table='.$session->dining_table_id,
                ]];
            }

            return $item->fresh();
        });
        if (is_array($push)) {
            WaiterPushService::notify($push[0], $push[1]);
        }

        return $item;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{session: DiningSession, tickets: list<array<string, mixed>>}
     */
    public function trackItemsDone(DiningSession $session, array $itemIds): array
    {
        return DB::transaction(function () use ($session, $itemIds) {
            $session = DiningSession::query()->with('outlet')->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['occupied', 'pending_bill'], true)) {
                throw ValidationException::withMessages(['session' => ['Kitchen items can only be completed for an open dining session.']]);
            }
            if ($session->outlet?->order_flow !== OutletOrderFlow::DirectBill->value) {
                throw ValidationException::withMessages(['session' => ['Live tracking is only for direct to bill outlets.']]);
            }

            $ids = collect($itemIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values();
            $items = OrderItem::query()
                ->whereIn('id', $ids)
                ->whereHas('order', fn ($query) => $query->where('dining_session_id', $session->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($ids->isEmpty() || $items->count() !== $ids->count()) {
                throw ValidationException::withMessages(['item_ids' => ['Every item must belong to this dining session.']]);
            }
            if ($items->contains(fn (OrderItem $item) => $item->service_route !== FulfillmentMode::Kitchen->value || $item->status === OrderItemStatus::Cancelled->value)) {
                throw ValidationException::withMessages(['item_ids' => ['Only non-cancelled kitchen-preparation items can be completed.']]);
            }

            $orders = Order::query()->whereIn('id', $items->pluck('order_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $completedAt = now();
            OrderItem::query()->whereIn('id', $ids)->whereNull('tracked_done_at')->update(['tracked_done_at' => $completedAt]);
            $tickets = [];

            foreach ($items->groupBy('order_id') as $orderId => $orderItems) {
                $order = $orders->get((int) $orderId);
                if (! $order) {
                    continue;
                }
                $remaining = $order->items()->where('service_route', FulfillmentMode::Kitchen->value)->where('status', '!=', OrderItemStatus::Cancelled->value)->whereNull('tracked_done_at')->get();
                $complete = $remaining->isEmpty();
                if ($complete && ! $order->tracked_done_at) {
                    $order->update(['tracked_done_at' => $completedAt]);
                }
                $order->load(['diningSession.diningTable:id,name,code', 'diningSession.joinedTables:id,name', 'creator:id,name']);
                $order->setRelation('items', $complete
                    ? $order->items()->where('service_route', FulfillmentMode::Kitchen->value)->where('status', '!=', OrderItemStatus::Cancelled->value)->get()
                    : $remaining);
                $ticket = RestaurantRealtime::liveTicket($order);
                $completedIds = $orderItems->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                $payload = [
                    'completed_item_id' => count($completedIds) === 1 ? $completedIds[0] : null,
                    'completed_item_ids' => $completedIds,
                    'ticket_complete' => $complete,
                    $complete ? 'done_ticket' : 'queue_ticket' => $ticket,
                ];
                RestaurantRealtime::dispatchToMembers('live_item_done', $order->diningSession, $payload, $order->id, count($completedIds) === 1 ? $completedIds[0] : null);
                $tickets[] = $payload;
            }

            return [
                'session' => $session->fresh()->load('diningTable', 'waiter', 'joinedTables:id,name,code', 'orders.items.product.category:id,name,print_on_bill', 'invoice'),
                'tickets' => $tickets,
            ];
        });
    }
}
