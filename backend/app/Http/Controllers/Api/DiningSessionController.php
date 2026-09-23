<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DiningBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiningSessionController extends ApiController
{
    public function open(Request $request, int $table, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $table = DiningTable::query()->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotel->id))->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))->with('outlet:id,hotel_id')->findOrFail($table);
        $data = $request->validate(['waiter_id' => ['nullable', 'integer'], 'customer_id' => ['nullable', 'integer'], 'guest_count' => ['sometimes', 'integer', 'min:1', 'max:999'], 'notes' => ['nullable', 'string']]);
        if (! empty($data['waiter_id'])) {
            abort_unless((int) $data['waiter_id'] === (int) $request->user()->id || in_array('sessions.assign', $request->attributes->get('currentPermissions', []), true) || $request->attributes->get('currentRole')?->is_owner, 403, 'You cannot assign a session to another waiter.');
            $this->activeMember($hotel->id, $data['waiter_id']);
        } if (! empty($data['customer_id'])) {
            Customer::query()->where('hotel_id', $hotel->id)->findOrFail($data['customer_id']);
        }
        $session = $service->openSession($table, $request->user(), $data);

        return $this->success($this->session($session), 'Dining session opened.', 201);
    }

    public function staff(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $outletId = $request->attributes->get('currentOutletId');
        $roles = $hotel->roles()->with('permissions')->get()->keyBy('id');
        $members = $hotel->users()
            ->wherePivot('is_active', true)
            ->with(['accessibleOutlets' => fn ($query) => $query->where('outlets.hotel_id', $hotel->id)])
            ->orderBy('users.name')
            ->get()
            ->filter(function (User $user) use ($roles, $outletId) {
                $role = $roles->get($user->pivot->role_id);
                if ($role === null) {
                    return false;
                }
                $codes = $role->permissions->pluck('code');
                $canTakeOrders = $role->is_owner || $codes->contains('sessions.open') || $codes->contains('orders.create');
                if (! $canTakeOrders) {
                    return false;
                }
                $hotelWide = $role->is_owner || $codes->contains('outlets.all');

                return $hotelWide || $outletId === null || $user->accessibleOutlets->contains('id', $outletId);
            })
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values();

        return $this->success($members);
    }

    public function assignWaiter(Request $request, int $session, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $session = $this->sessions($request)->findOrFail($session);
        $data = $request->validate(['waiter_id' => ['required', 'integer']]);
        $waiter = $this->activeMember($hotel->id, $data['waiter_id']);
        $membership = $hotel->users()->whereKey($waiter->id)->firstOrFail();
        $role = $hotel->roles()->with('permissions')->findOrFail($membership->pivot->role_id);
        $hotelWide = $role->is_owner || $role->permissions->contains('code', 'outlets.all');
        abort_unless($hotelWide || $waiter->accessibleOutlets()->where('outlets.id', $session->outlet_id)->exists(), 422, 'That waiter does not have access to this outlet.');

        return $this->success($this->session($service->assignWaiter($session, $waiter)), 'Waiter assigned.');
    }

    public function show(Request $request, int $session): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->success($this->session($this->sessions($request)->findOrFail($session)));
    }

    public function addOrder(Request $request, int $session, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $session = $this->sessions($request)->findOrFail($session);
        $data = $request->validate(['notes' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'], 'items.*.kitchen_note' => ['nullable', 'string', 'max:1000']]);

        return $this->success($this->session($service->addOrder($session, $request->user(), $data['items'], $data['notes'] ?? null)), 'Order round sent.', 201);
    }

    public function requestBill(Request $request, int $session, DiningBillingService $service): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $session = $this->sessions($request)->findOrFail($session);

        return $this->success($this->session($service->requestBill($session)), 'Bill requested.');
    }

    public function closeWithoutSale(Request $request, int $session, DiningBillingService $service, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $session = $this->sessions($request)->findOrFail($session);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->success($this->session($service->closeWithoutSale($session, $request->user(), $data['reason'], $audit)), 'Table closed without a sale.');
    }

    public function discount(Request $request, int $session, DiningBillingService $service, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $session = $this->sessions($request)->findOrFail($session);
        $data = $request->validate(['discount_amount' => ['required', 'numeric', 'min:0']]);

        $session = $service->applyDiscount($session, (float) $data['discount_amount']);
        $audit->record($session, 'billing.discount_applied', $request->user(), $hotel->id, $session->outlet_id, ['amount' => $data['discount_amount']]);

        return $this->success($this->session($session), 'Discount applied.');
    }

    private function session(DiningSession $session): DiningSession
    {
        $session->load('diningTable:id,outlet_id,name,code,capacity,service_type', 'waiter:id,name', 'customer:id,name,phone', 'orders.items.product:id,name,sku', 'invoice.chargedTo:id,name');
        $history = collect([['status' => 'session_opened', 'occurred_at' => $session->opened_at?->toISOString()]])
            ->merge($session->orders->flatMap(function ($order) {
                $entries = collect([['status' => 'order_sent', 'order_id' => $order->id, 'occurred_at' => $order->sent_at?->toISOString()]]);
                return $entries->merge($order->items->flatMap(fn ($item) => collect([
                    $item->preparing_at ? ['status' => 'item_preparing', 'order_id' => $order->id, 'item_id' => $item->id, 'occurred_at' => $item->preparing_at->toISOString()] : null,
                    $item->ready_at ? ['status' => 'item_ready', 'order_id' => $order->id, 'item_id' => $item->id, 'occurred_at' => $item->ready_at->toISOString()] : null,
                    $item->served_at ? ['status' => 'item_served', 'order_id' => $order->id, 'item_id' => $item->id, 'occurred_at' => $item->served_at->toISOString()] : null,
                    $item->cancelled_at ? ['status' => 'item_cancelled', 'order_id' => $order->id, 'item_id' => $item->id, 'occurred_at' => $item->cancelled_at->toISOString()] : null,
                ])->filter()));
            }))->filter(fn ($entry) => $entry['occurred_at'] ?? null)->sortBy('occurred_at')->values();
        $session->setAttribute('status_history', $history);

        return $session;
    }

    private function sessions(Request $request)
    {
        return DiningSession::query()->where('hotel_id', $request->attributes->get('currentHotel')->id)
            ->whereIn('outlet_id', $request->attributes->get('accessibleOutletIds', []))
            ->when($request->attributes->get('currentOutletId'), fn ($query, $id) => $query->where('outlet_id', $id));
    }

    private function activeMember(int $hotelId, int $userId): User
    {
        return User::query()->whereHas('hotels', fn ($q) => $q->where('hotels.id', $hotelId)->where('hotel_user.is_active', true))->findOrFail($userId);
    }
}
