<?php

namespace App\Services;

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
}
