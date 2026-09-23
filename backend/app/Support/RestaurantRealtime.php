<?php

namespace App\Support;

use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\OrderItem;

class RestaurantRealtime
{
    /**
     * Floor and kitchen clients patch from this payload instead of refetching the whole board.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function payload(?DiningSession $session, array $extra = []): array
    {
        if (! $session) {
            return $extra;
        }

        $session->loadMissing(['diningTable:id,name,code,outlet_id,is_active,waiter_called_at', 'waiter:id,name', 'invoice:id,invoice_number,status,payment_status']);
        $items = $session->relationLoaded('orders')
            ? $session->orders->flatMap->items
            : OrderItem::query()->whereHas('order', fn ($query) => $query->where('dining_session_id', $session->id))->get();
        $active = $items->where('status', '!=', 'cancelled');
        $progress = [
            'pending' => $active->where('status', 'pending')->count(),
            'preparing' => $active->where('status', 'preparing')->count(),
            'ready' => $active->where('status', 'ready')->count(),
            'served' => $active->where('status', 'served')->count(),
        ];
        $closed = $session->status === 'closed';
        $display = ! ($session->diningTable?->is_active ?? true) || $closed
            ? 'available'
            : ($session->status === 'pending_bill' ? 'pending_bill' : (($progress['served'] ?? 0) > 0 ? 'food_serving' : 'occupied'));

        return array_merge([
            'table_name' => $session->diningTable?->name,
            'display_status' => $display,
            'current_total' => (string) $session->total_amount,
            'session_status' => $session->status,
            'guest_count' => $session->guest_count,
            'waiter_id' => $session->waiter_id,
            'waiter_name' => $session->waiter?->name,
            'kitchen_progress' => $progress,
            'waiter_called' => $session->diningTable?->waiter_called_at !== null,
            'invoice_id' => $session->invoice?->id,
            'invoice_number' => $session->invoice?->invoice_number,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public static function kitchenItem(OrderItem $item): array
    {
        $item->loadMissing(['order.diningSession.diningTable:id,name,code', 'order.creator:id,name']);
        $order = $item->order;

        return [
            'id' => $item->id,
            'item_name' => $item->item_name,
            'quantity' => (string) $item->quantity,
            'status' => $item->status,
            'kitchen_note' => $item->kitchen_note,
            'kitchen_station_id' => $item->kitchen_station_id,
            'fulfillment_mode' => $item->fulfillment_mode,
            'created_at' => $item->created_at?->toISOString(),
            'preparing_at' => $item->preparing_at?->toISOString(),
            'ready_at' => $item->ready_at?->toISOString(),
            'order' => $order ? [
                'id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'round_number' => $order->round_number,
                'sent_at' => $order->sent_at?->toISOString() ?? $order->created_at?->toISOString(),
                'creator' => $order->creator ? ['id' => $order->creator->id, 'name' => $order->creator->name] : null,
                'dining_session' => [
                    'dining_table' => $order->diningSession?->diningTable
                        ? ['name' => $order->diningSession->diningTable->name, 'code' => $order->diningSession->diningTable->code]
                        : null,
                ],
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function table(DiningTable $table, ?DiningSession $session = null, array $extra = []): array
    {
        $base = $session
            ? self::payload($session)
            : ['display_status' => 'available', 'current_total' => '0.00', 'waiter_called' => false];

        return array_merge($base, [
            'table_name' => $table->name,
            'waiter_called' => $table->waiter_called_at !== null,
        ], $extra);
    }
}
