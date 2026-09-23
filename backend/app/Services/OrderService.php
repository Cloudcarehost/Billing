<?php

namespace App\Services;

use App\Events\RestaurantUpdated;
use App\Models\DiningSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DiningSessionGuard;
use App\Support\Money;
use App\Support\RestaurantRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function cancelItem(OrderItem $item, string $reason, User $user, InventoryService $inventory): OrderItem
    {
        return DB::transaction(function () use ($item, $reason, $user, $inventory) {
            $item = OrderItem::query()->with(['order.diningSession.hotel', 'order.diningSession.diningTable', 'order.diningSession.invoice', 'product'])->lockForUpdate()->findOrFail($item->id);
            DiningSessionGuard::assertOccupied($item->order->diningSession);
            if ($item->status === 'cancelled') {
                throw ValidationException::withMessages(['item' => ['This item can no longer be cancelled.']]);
            } $wasPrepared = $item->fulfillment_mode === 'kitchen' && in_array($item->status, ['preparing', 'ready', 'served'], true);
            $item->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);
            if ($item->inventory_deducted) {
                $inventory->reverseForCancellation($item, $user, $wasPrepared);
            } $session = $item->order->diningSession;
            $this->synchronizeStatus($item->order);
            $this->recalculate($session);
            RestaurantUpdated::dispatch('item_cancelled', $session->hotel_id, $session->outlet_id, $session->dining_table_id, $session->id, $item->order_id, $item->id, $session->waiter_id, $item->kitchen_station_id, RestaurantRealtime::payload($session->load('diningTable', 'waiter', 'orders.items'), [
                'status' => 'cancelled',
                'reason' => $reason,
                'table_name' => $session->diningTable?->name,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'kitchen_item' => RestaurantRealtime::kitchenItem($item),
            ]));

            return $item->fresh();
        });
    }

    public function synchronizeStatus(Order $order): void
    {
        $items = $order->items()->get();
        $active = $items->where('status', '!=', 'cancelled');

        if ($active->isEmpty()) {
            $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return;
        }

        if ($active->every(fn (OrderItem $item) => $item->status === 'served')) {
            $order->update(['status' => 'served', 'served_at' => now()]);

            return;
        }

        if ($active->every(fn (OrderItem $item) => in_array($item->status, ['ready', 'served'], true))) {
            $order->update(['status' => 'ready', 'ready_at' => now()]);

            return;
        }

        if ($active->contains(fn (OrderItem $item) => $item->status === 'preparing')) {
            $order->update(['status' => 'preparing', 'started_at' => $order->started_at ?? now()]);

            return;
        }

        $order->update(['status' => 'pending']);
    }

    public function recalculate(DiningSession $session): void
    {
        $items = $session->orders()->with('items')->get()->flatMap->items->where('status', '!=', 'cancelled');
        $subtotalMinor = $items->sum(fn ($item) => Money::toMinor($item->line_subtotal));
        $taxMinor = $items->sum(fn ($item) => Money::toMinor($item->tax_amount));
        $totalMinor = $subtotalMinor + $taxMinor - Money::toMinor($session->discount_amount) + Money::toMinor($session->service_charge_amount);
        $session->update(['subtotal' => Money::fromMinor($subtotalMinor), 'tax_amount' => Money::fromMinor($taxMinor), 'total_amount' => Money::fromMinor($totalMinor)]);
    }
}
