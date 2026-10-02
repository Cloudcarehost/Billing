<?php

namespace App\Services;

use App\Models\DiningSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DiningSessionGuard;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\RestaurantRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function cancelItem(OrderItem $item, string $reason, User $user, InventoryService $inventory, ?float $quantity = null): OrderItem
    {
        return DB::transaction(function () use ($item, $reason, $user, $inventory, $quantity) {
            $item = OrderItem::query()->with(['order.diningSession.hotel', 'order.diningSession.diningTable', 'order.diningSession.invoice', 'product'])->lockForUpdate()->findOrFail($item->id);
            DiningSessionGuard::assertOccupied($item->order->diningSession);
            if ($item->status === 'cancelled') {
                throw ValidationException::withMessages(['item' => ['This item can no longer be cancelled.']]);
            }
            $itemMilli = Quantity::toMilli($item->quantity);
            $cancelMilli = $quantity === null ? $itemMilli : Quantity::toMilli($quantity);
            if ($cancelMilli <= 0 || $cancelMilli > $itemMilli) {
                throw ValidationException::withMessages(['quantity' => ['Enter a quantity that is still on this line.']]);
            }
            $wasPrepared = $item->fulfillment_mode === 'kitchen' && in_array($item->status, ['preparing', 'ready', 'served'], true);
            $cancelled = $cancelMilli >= $itemMilli
                ? $this->voidLine($item, $reason, $user)
                : $this->splitCancelledQty($item, $cancelMilli, $reason, $user);
            if ($cancelled->inventory_deducted) {
                $inventory->reverseForCancellation($cancelled, $user, $wasPrepared);
            }
            $session = $item->order->diningSession;
            $this->synchronizeStatus($item->order);
            $this->recalculate($session);
            $session->load(['diningTable', 'waiter', 'joinedTables:id,name', 'orders.items']);
            RestaurantRealtime::dispatchToMembers('item_cancelled', $session, [
                'status' => 'cancelled',
                'reason' => $reason,
                'item_name' => $cancelled->item_name,
                'quantity' => $cancelled->quantity,
                'kitchen_item' => RestaurantRealtime::kitchenItem($cancelled),
            ], $cancelled->order_id, $cancelled->id, $cancelled->kitchen_station_id);

            return $cancelled->fresh();
        });
    }

    private function voidLine(OrderItem $item, string $reason, User $user): OrderItem
    {
        $item->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);

        return $item;
    }

    private function splitCancelledQty(OrderItem $item, int $cancelMilli, string $reason, User $user): OrderItem
    {
        $remainingMilli = Quantity::toMilli($item->quantity) - $cancelMilli;
        $includesTax = (bool) $item->product?->price_includes_tax;
        $remaining = Money::taxedLine($item->unit_price, Quantity::fromMilli($remainingMilli), $item->tax_rate, $includesTax);
        $cancelledLine = Money::taxedLine($item->unit_price, Quantity::fromMilli($cancelMilli), $item->tax_rate, $includesTax);
        $cancelled = $item->order->items()->create([
            'product_id' => $item->product_id,
            'kitchen_station_id' => $item->kitchen_station_id,
            'fulfillment_mode' => $item->fulfillment_mode,
            'item_name' => $item->item_name,
            'sku' => $item->sku,
            'unit' => $item->unit,
            'quantity' => Quantity::fromMilli($cancelMilli),
            'unit_price' => $item->unit_price,
            'unit_cost' => $item->unit_cost,
            'discount_amount' => 0,
            'tax_rate' => $item->tax_rate,
            'tax_amount' => $cancelledLine['tax'],
            'line_subtotal' => $cancelledLine['subtotal'],
            'line_total' => $cancelledLine['total'],
            'kitchen_note' => $item->kitchen_note,
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $reason,
            'inventory_deducted' => $item->inventory_deducted,
            'preparing_at' => $item->preparing_at,
            'ready_at' => $item->ready_at,
            'served_at' => $item->served_at,
        ]);
        $item->update([
            'quantity' => Quantity::fromMilli($remainingMilli),
            'tax_amount' => $remaining['tax'],
            'line_subtotal' => $remaining['subtotal'],
            'line_total' => $remaining['total'],
        ]);

        return $cancelled;
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
        $session->refreshTotals();
    }
}
