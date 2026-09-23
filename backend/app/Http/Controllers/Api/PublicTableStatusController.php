<?php

namespace App\Http\Controllers\Api;

use App\Events\RestaurantUpdated;
use App\Models\TablePublicLink;
use App\Services\WaiterPushService;
use App\Support\RestaurantRealtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicTableStatusController extends ApiController
{
    public function show(Request $request, string $token): JsonResponse
    {
        $link = $this->activeLink($token);
        $table = $link->diningTable;
        $session = $table->activeSession;
        $updatedAt = collect([$table->updated_at, $session?->updated_at, $link->updated_at, $table->waiter_called_at]);

        if ($session) {
            foreach ($session->orders as $order) {
                $updatedAt->push($order->updated_at);
                foreach ($order->items as $item) {
                    $updatedAt->push($item->updated_at);
                }
            }
        }

        $data = [
            'hotel_name' => $table->outlet->hotel->name,
            'outlet_name' => $table->outlet->name,
            'table_name' => $table->name,
            'currency' => $table->outlet->hotel->currency_code,
            'waiter_called' => $table->waiter_called_at !== null,
            'active_order' => $session ? [
                'items' => $session->orders->flatMap(fn ($order) => $order->items)->map(fn ($item) => [
                    'name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'status' => $item->status,
                    'fulfillment_mode' => $item->fulfillment_mode,
                ])->values(),
                'subtotal' => $session->subtotal,
                'tax' => $session->tax_amount,
                'total' => $session->total_amount,
                'bill_requested' => $session->status === 'pending_bill',
            ] : null,
            'order_flow' => $table->outlet->order_flow,
            'last_updated_at' => $updatedAt->filter()->max()?->toISOString(),
        ];

        $etag = '"'.hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, no-cache, max-age=0']);
        }

        return $this->success($data)->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, no-cache, max-age=0']);
    }

    public function callWaiter(string $token): JsonResponse
    {
        $link = $this->activeLink($token);
        $table = $link->diningTable;
        $recent = $table->waiter_called_at !== null && $table->waiter_called_at->gt(now()->subSeconds(45));
        if (! $recent) {
            $table->update(['waiter_called_at' => now()]);
            $session = $table->activeSession;
            RestaurantUpdated::dispatch('waiter_called', $table->outlet->hotel_id, $table->outlet_id, $table->id, $session?->id, null, null, $session?->waiter_id, null, RestaurantRealtime::table($table, $session, [
                'table_name' => $table->name,
                'waiter_called' => true,
            ]));
            WaiterPushService::notify($session?->waiter_id, [
                'title' => $table->name.': waiter needed',
                'body' => 'A guest asked for a waiter at this table.',
                'tag' => 'call-'.$table->id,
                'url' => '/app/orders?table='.$table->id,
            ]);
        }

        return $this->success(['waiter_called' => true], $recent ? 'Waiter already notified.' : 'Waiter has been notified.');
    }

    private function activeLink(string $token): TablePublicLink
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        return TablePublicLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->with([
                'diningTable.outlet.hotel:id,name,currency_code',
                'diningTable.activeSession.orders.items' => fn ($query) => $query->where('status', '!=', 'cancelled')->orderBy('id'),
            ])->firstOrFail();
    }
}
