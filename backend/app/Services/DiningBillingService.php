<?php

namespace App\Services;

use App\Enums\FulfillmentMode;
use App\Enums\OutletOrderFlow;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\TaxType;
use App\Events\RestaurantUpdated;
use App\Models\Customer;
use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\DiningSessionGuard;
use App\Support\HotelDate;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\RestaurantRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiningBillingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderService $orders,
        private readonly InvoiceSequenceService $sequences,
    ) {}

    public function openSession(DiningTable $table, User $user, array $data): DiningSession
    {
        return DB::transaction(function () use ($table, $user, $data) {
            $table = DiningTable::query()->lockForUpdate()->findOrFail($table->id);
            if (! $table->is_active) {
                $this->invalid('table', 'This dining table is inactive.');
            }
            if (DiningSession::occupying($table->id, true)) {
                $this->invalid('table', 'This dining table already has an active session.');
            }
            $session = DiningSession::query()->create(['hotel_id' => $table->outlet->hotel_id, 'outlet_id' => $table->outlet_id, 'dining_table_id' => $table->id, 'waiter_id' => $data['waiter_id'] ?? $user->id, 'customer_id' => $data['customer_id'] ?? null, 'guest_count' => $data['guest_count'] ?? 1, 'notes' => $data['notes'] ?? null, 'status' => 'occupied']);
            ReportService::invalidateDashboard($session->hotel_id);
            RestaurantUpdated::dispatch('session_opened', $session->hotel_id, $session->outlet_id, $session->dining_table_id, $session->id, null, null, $session->waiter_id, null, RestaurantRealtime::payload($session->load('diningTable', 'waiter')));

            return $session;
        });
    }

    public function assignWaiter(DiningSession $session, User $waiter): DiningSession
    {
        return DB::transaction(function () use ($session, $waiter) {
            $session = DiningSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['occupied', 'pending_bill'], true)) {
                $this->invalid('session', 'A waiter can only be assigned on an open dining session.');
            }
            $session->update(['waiter_id' => $waiter->id]);
            RestaurantRealtime::dispatchToMembers('session_assigned', $session->fresh(['diningTable', 'waiter', 'joinedTables:id,name']), [
                'waiter_id' => $waiter->id,
                'waiter_name' => $waiter->name,
            ]);

            return $session->fresh();
        });
    }

    /**
     * @param  list<int>  $tableIds
     */
    public function mergeTables(DiningSession $session, array $tableIds, ?int $primaryTableId = null): DiningSession
    {
        return DB::transaction(function () use ($session, $tableIds, $primaryTableId) {
            $session = DiningSession::query()->with('joinedTables:id,name', 'diningTable')->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['occupied', 'pending_bill'], true)) {
                $this->invalid('session', 'Only an open session can merge tables.');
            }
            $wanted = collect($tableIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $primaryId = $primaryTableId ? (int) $primaryTableId : $session->dining_table_id;
            $group = collect($session->memberTableIds())->merge($wanted)->push($primaryId)->unique()->values();
            $tables = DiningTable::query()->whereIn('id', $group)->lockForUpdate()->get()->keyBy('id');
            $pending = $group->all();
            $seen = [];
            while ($pending !== []) {
                $tableId = (int) array_shift($pending);
                if (isset($seen[$tableId])) {
                    continue;
                }
                $seen[$tableId] = true;
                $table = $tables->get($tableId) ?? DiningTable::query()->lockForUpdate()->find($tableId);
                if (! $table) {
                    $this->invalid('table_ids', 'One or more tables were not found.');
                }
                $tables[$tableId] = $table;
                if ($table->outlet_id !== $session->outlet_id) {
                    $this->invalid('table_ids', 'Merged tables must belong to the same outlet.');
                }
                if ($table->isParcel()) {
                    $this->invalid('table_ids', 'Parcel orders cannot be merged with dine-in tables.');
                }
                if (! $table->is_active) {
                    $this->invalid('table_ids', 'An inactive table cannot be merged.');
                }
                $other = DiningSession::occupying($tableId, true);
                if ($other && $other->id !== $session->id) {
                    $absorbed = $other->memberTableIds();
                    $this->absorbSession($other, $session);
                    foreach ($absorbed as $absorbedId) {
                        if (! isset($seen[$absorbedId])) {
                            $pending[] = $absorbedId;
                        }
                    }
                    $group = $group->merge($absorbed)->unique()->values();
                }
            }
            if (! $tables->has($primaryId)) {
                $this->invalid('primary_table_id', 'The main table must be part of the merged group.');
            }
            $extras = $group->reject(fn ($id) => $id === $primaryId)->all();
            $session->joinedTables()->sync($extras);
            if ($primaryId !== $session->dining_table_id) {
                $session->update(['dining_table_id' => $primaryId]);
            }
            $this->recalculate($session);
            $session = $session->fresh()->load('diningTable', 'waiter', 'joinedTables:id,name,code', 'orders.items');
            RestaurantRealtime::dispatchToMembers('tables_merged', $session);

            return $session;
        });
    }

    public function unmergeTable(DiningSession $session, int $tableId): DiningSession
    {
        return DB::transaction(function () use ($session, $tableId) {
            $session = DiningSession::query()->with('joinedTables:id,name')->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['occupied', 'pending_bill'], true)) {
                $this->invalid('session', 'Only an open session can release a merged table.');
            }
            if ($tableId === $session->dining_table_id) {
                $this->invalid('table', 'Choose a different main table before removing this one.');
            }
            if (! $session->joinedTables->contains('id', $tableId)) {
                $this->invalid('table', 'That table is not part of this merged group.');
            }
            $session->joinedTables()->detach($tableId);
            $table = DiningTable::query()->find($tableId);
            RestaurantUpdated::dispatch('table_closed', $session->hotel_id, $session->outlet_id, $tableId, $session->id, null, null, $session->waiter_id, null, RestaurantRealtime::payload($session->fresh(['diningTable', 'waiter', 'joinedTables:id,name']), [
                'joined_table_ids' => [$tableId],
                'reason' => 'unmerged',
                'table_name' => $table?->name,
            ]));
            $session = $session->fresh()->load('diningTable', 'waiter', 'joinedTables:id,name,code', 'orders.items');
            RestaurantRealtime::dispatchToMembers('tables_merged', $session);

            return $session;
        });
    }

    public function addOrder(DiningSession $session, User $user, array $items, ?string $notes = null): DiningSession
    {
        return DB::transaction(function () use ($session, $user, $items, $notes) {
            $session = DiningSession::query()->with(['hotel', 'outlet'])->lockForUpdate()->findOrFail($session->id);
            DiningSessionGuard::assertOccupied($session);
            $directBill = $session->outlet?->order_flow === OutletOrderFlow::DirectBill->value;
            $round = ((int) $session->orders()->lockForUpdate()->max('round_number')) + 1;
            $order = $session->orders()->create(['created_by' => $user->id, 'ticket_number' => "ORD-{$session->id}-{$round}", 'round_number' => $round, 'notes' => $notes]);
            $products = Product::query()->where('hotel_id', $session->hotel_id)->whereIn('id', collect($items)->pluck('product_id'))->where('is_active', true)->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    $this->invalid('items', 'One or more selected products are not available for this hotel.');
                }
                $quantity = (float) $item['quantity'];
                $isDirect = $directBill || $product->fulfillment_mode === FulfillmentMode::Direct->value;
                foreach ($this->ticketQuantities($product, $quantity, $isDirect) as $unitQty) {
                    $line = Money::taxedLine($product->selling_price, $unitQty, $product->tax_rate, (bool) $product->price_includes_tax);
                    $orderItem = $order->items()->create([
                        'product_id' => $product->id,
                        'kitchen_station_id' => $isDirect ? null : $product->kitchen_station_id,
                        'fulfillment_mode' => $isDirect ? FulfillmentMode::Direct->value : $product->fulfillment_mode,
                        'item_name' => $product->name,
                        'sku' => $product->sku,
                        'unit' => $product->unit,
                        'quantity' => $unitQty,
                        'unit_price' => $product->selling_price,
                        'unit_cost' => $product->cost_price,
                        'tax_rate' => $product->tax_rate,
                        'tax_amount' => $line['tax'],
                        'line_subtotal' => $line['subtotal'],
                        'line_total' => $line['total'],
                        'kitchen_note' => $isDirect ? null : ($item['kitchen_note'] ?? null),
                        'status' => $isDirect ? 'ready' : 'pending',
                        'ready_at' => $isDirect ? now() : null,
                    ]);
                    $deductOnSend = $directBill || $session->hotel->inventory_deduction_rule !== 'served';
                    if ($isDirect && $deductOnSend) {
                        $this->inventory->deductForItem($orderItem->load('product', 'order.diningSession.hotel'), $user);
                        $orderItem->update(['inventory_deducted' => true]);
                    }
                }
            }
            $this->orders->synchronizeStatus($order);
            $this->recalculate($session);
            $order->load(['items', 'creator:id,name', 'diningSession.diningTable', 'diningSession.joinedTables:id,name']);
            $session->load(['diningTable', 'waiter', 'joinedTables:id,name,code', 'orders.items']);
            $payload = [
                'ticket_number' => $order->ticket_number,
                'kitchen_items' => $directBill ? [] : $order->items->where('fulfillment_mode', 'kitchen')->map(fn ($item) => RestaurantRealtime::kitchenItem($item))->values()->all(),
            ];
            if ($directBill) {
                $payload['live_ticket'] = RestaurantRealtime::liveTicket($order);
            }
            RestaurantRealtime::dispatchToMembers('order_sent', $session, $payload, $order->id);

            return $session->fresh();
        });
    }

    public function moveOpenKitchenItemsToBill(Outlet $outlet, User $user): int
    {
        return DB::transaction(function () use ($outlet, $user) {
            $sessionIds = DiningSession::query()
                ->where('outlet_id', $outlet->id)
                ->whereIn('status', ['occupied', 'pending_bill'])
                ->lockForUpdate()
                ->pluck('id');
            $items = $sessionIds->isEmpty()
                ? collect()
                : OrderItem::query()
                    ->with(['product', 'order.diningSession.hotel'])
                    ->whereHas('order', fn ($query) => $query->whereIn('dining_session_id', $sessionIds))
                    ->whereIn('status', ['pending', 'preparing', 'ready'])
                    ->lockForUpdate()
                    ->get();
            $ids = $items->pluck('id');
            if ($ids->isNotEmpty()) {
                OrderItem::query()->whereIn('id', $ids)->update([
                    'fulfillment_mode' => FulfillmentMode::Direct->value,
                    'kitchen_station_id' => null,
                    'status' => 'ready',
                    'kitchen_note' => null,
                ]);
                OrderItem::query()->whereIn('id', $ids)->whereNull('ready_at')->update(['ready_at' => now()]);
                foreach ($items->where('inventory_deducted', false) as $item) {
                    $this->inventory->deductForItem($item, $user);
                    $item->update(['inventory_deducted' => true]);
                }
                foreach (Order::query()->whereIn('dining_session_id', $sessionIds)->get() as $order) {
                    $this->orders->synchronizeStatus($order);
                }
            }
            RestaurantUpdated::dispatch('outlet_flow_changed', $outlet->hotel_id, $outlet->id, null, null, null, null, null, null, [
                'order_flow' => $outlet->order_flow,
                'moved_item_count' => $ids->count(),
            ]);

            return $ids->count();
        });
    }

    public function requestBill(DiningSession $session): DiningSession
    {
        return DB::transaction(function () use ($session) {
            $session = DiningSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'occupied') {
                $this->invalid('session', 'Only an occupied session can request a bill.');
            } $this->recalculate($session);
            $session->update(['status' => 'pending_bill']);
            ReportService::invalidateDashboard($session->hotel_id);
            RestaurantRealtime::dispatchToMembers('bill_requested', $session->load('diningTable', 'waiter', 'joinedTables:id,name', 'orders.items'));

            return $session->fresh();
        });
    }

    public function closeWithoutSale(DiningSession $session, User $user, string $reason, AuditService $audit): DiningSession
    {
        return DB::transaction(function () use ($session, $user, $reason, $audit) {
            $session = DiningSession::query()->with('orders.items')->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'pending_bill') {
                $this->invalid('session', 'Request the bill before closing a zero-value session without a sale.');
            }
            if ($session->invoice()->exists()) {
                $this->invalid('session', 'A session with an invoice cannot be closed without a sale.');
            }
            $this->recalculate($session);
            if (Money::toMinor($session->total_amount) !== 0) {
                $this->invalid('session', 'Only a zero-value session can be closed without a sale.');
            }

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_without_sale_by' => $user->id,
                'closed_without_sale_reason' => $reason,
            ]);
            $audit->record($session, 'dining_session.closed_without_sale', $user, $session->hotel_id, $session->outlet_id, ['reason' => $reason]);
            ReportService::invalidateDashboard($session->hotel_id);
            $this->closeMembers($session, ['reason' => 'closed_without_sale']);

            return $session->fresh();
        });
    }

    public function cancelParcel(DiningSession $session, User $user, AuditService $audit): DiningSession
    {
        return DB::transaction(function () use ($session, $user, $audit) {
            $session = DiningSession::query()->with(['diningTable', 'orders.items'])->lockForUpdate()->findOrFail($session->id);
            if (! $session->diningTable?->isParcel()) {
                $this->invalid('session', 'Only a parcel order can be cancelled this way.');
            }
            if (! in_array($session->status, ['occupied', 'pending_bill'], true)) {
                $this->invalid('session', 'This parcel is no longer open.');
            }
            if ($session->invoice()->exists()) {
                $this->invalid('session', 'Void the final bill before cancelling this parcel.');
            }

            $itemIds = $session->orders->flatMap->items->where('status', '!=', 'cancelled')->pluck('id');
            foreach ($itemIds as $itemId) {
                $this->orders->cancelItem(OrderItem::query()->findOrFail($itemId), 'Parcel cancelled', $user, $this->inventory);
            }

            $session = DiningSession::query()->lockForUpdate()->findOrFail($session->id);
            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_without_sale_by' => $user->id,
                'closed_without_sale_reason' => 'Parcel cancelled',
            ]);
            $audit->record($session, 'dining_session.parcel_cancelled', $user, $session->hotel_id, $session->outlet_id, [
                'items_cancelled' => $itemIds->count(),
            ]);
            ReportService::invalidateDashboard($session->hotel_id);
            $this->closeMembers($session, ['reason' => 'parcel_cancelled']);

            return $session->fresh();
        });
    }

    public function applyDiscount(DiningSession $session, ?float $amount = null, ?float $percent = null): DiningSession
    {
        return DB::transaction(function () use ($session, $amount, $percent) {
            $session = DiningSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['occupied', 'pending_bill'], true) || $session->invoice()->exists()) {
                $this->invalid('session', 'Discounts can only be changed before an invoice is created.');
            }
            $session->refreshTotals();
            $maximumMinor = Money::toMinor($session->subtotal) + Money::toMinor($session->tax_amount) + Money::toMinor($session->service_charge_amount);
            if ($percent !== null) {
                if ($percent < 0 || $percent > 100) {
                    $this->invalid('discount_percent', 'Discount percent must be between 0 and 100.');
                }
                $session->update([
                    'discount_percent' => $percent,
                    'discount_amount' => Money::fromMinor((int) round($maximumMinor * $percent / 100, 0, PHP_ROUND_HALF_UP)),
                ]);
            } else {
                $amountMinor = Money::toMinor($amount ?? 0);
                if ($amountMinor > $maximumMinor) {
                    $this->invalid('discount_amount', 'Discount cannot exceed the current bill total.');
                }
                $session->update([
                    'discount_percent' => null,
                    'discount_amount' => Money::fromMinor($amountMinor),
                ]);
            }
            $session->refreshTotals();

            return $session->fresh();
        });
    }

    public function createInvoice(DiningSession $session, User $user, Hotel $hotel, array $guest = []): Invoice
    {
        return DB::transaction(function () use ($session, $user, $hotel, $guest) {
            $session = DiningSession::query()->with('orders.items.product.category', 'customer')->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'pending_bill') {
                $this->invalid('session', 'Request the bill before creating an invoice.');
            }
            if ($session->invoice()->exists()) {
                $this->invalid('session', 'An invoice already exists for this dining session.');
            }
            $this->recalculate($session);
            if (Money::toMinor($session->total_amount) === 0) {
                $this->invalid('session', 'A zero-value session must be closed without a sale instead of creating an invoice.');
            }
            $businessDate = HotelDate::businessDate($hotel);
            $period = HotelDate::period($hotel);
            $outlet = Outlet::query()->lockForUpdate()->findOrFail($session->outlet_id);
            $number = $this->sequences->nextNumber($outlet->id, $period);
            [$cgst, $sgst] = Money::splitHalves($session->tax_amount);
            $guest = $this->guestSnapshot($session, $guest);
            if (! empty($guest['customer_id']) && ! $session->customer_id) {
                $session->update(['customer_id' => $guest['customer_id']]);
            }
            $chargedToId = $guest['charged_to_user_id'] ?? null;
            $invoice = Invoice::query()->create(['hotel_id' => $session->hotel_id, 'outlet_id' => $session->outlet_id, 'customer_id' => $guest['customer_id'], 'dining_session_id' => $session->id, 'created_by' => $user->id, 'charged_to_user_id' => $chargedToId, 'invoice_number' => sprintf('%s-%s-%06d', $outlet->invoice_prefix, $period, $number), 'status' => InvoiceStatus::Issued->value, 'payment_status' => PaymentStatus::Unpaid->value, 'business_date' => $businessDate, 'billed_at' => now(), 'customer_name' => $guest['customer_name'], 'customer_phone' => $guest['customer_phone'], 'customer_email' => $guest['customer_email'], 'customer_gstin' => $guest['customer_gstin'], 'subtotal' => $session->subtotal, 'discount_amount' => $session->discount_amount, 'tax_amount' => $session->tax_amount, 'cgst_amount' => $cgst, 'sgst_amount' => $sgst, 'service_charge_amount' => $session->service_charge_amount, 'total_amount' => $session->total_amount, 'balance_amount' => $session->total_amount]);
            foreach ($session->orders->flatMap->items->where('status', '!=', 'cancelled') as $item) {
                [$itemCgst, $itemSgst] = Money::splitHalves($item->tax_amount);
                $invoice->items()->create([
                    'product_id' => $item->product_id,
                    'order_item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'category_name' => $item->product?->category?->name,
                    'print_on_bill' => $item->product?->category?->print_on_bill ?? true,
                    'sku' => $item->sku,
                    'hsn_code' => $item->product?->hsn_code,
                    'unit' => $item->unit,
                    'serving_size' => $item->product?->serving_size,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'unit_cost' => $item->unit_cost,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate' => $item->tax_rate,
                    'tax_type' => $item->product?->price_includes_tax ? TaxType::Inclusive->value : TaxType::Exclusive->value,
                    'tax_amount' => $item->tax_amount,
                    'line_subtotal' => $item->line_subtotal,
                    'line_total' => $item->line_total,
                    'cgst_amount' => $itemCgst,
                    'sgst_amount' => $itemSgst,
                    'igst_amount' => 0,
                ]);
            }

            ReportService::invalidateDashboard($invoice->hotel_id);
            RestaurantRealtime::dispatchToMembers('invoice_created', $session->load('diningTable', 'waiter', 'invoice', 'joinedTables:id,name'), [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice->load('items', 'payments', 'diningSession.diningTable', 'chargedTo:id,name');
        });
    }

    public function recordPayment(Invoice $invoice, User $user, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $user, $data) {
            $invoice = Invoice::query()->with('diningSession')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued' || $invoice->payment_status === 'paid') {
                $this->invalid('invoice', 'This invoice cannot accept another payment.');
            }
            $amountMinor = Money::toMinor($data['amount']);
            $balanceMinor = Money::toMinor($invoice->balance_amount);
            if ($amountMinor > $balanceMinor) {
                $this->invalid('amount', 'Payment cannot exceed the remaining balance.');
            }
            $invoice->payments()->create(['received_by' => $user->id, 'type' => PaymentType::Payment->value, 'method' => $data['method'], 'amount' => Money::fromMinor($amountMinor), 'reference_number' => $data['reference_number'] ?? null, 'notes' => $data['notes'] ?? null, 'paid_at' => now()]);
            $paidMinor = $invoice->payments()->get()->sum(fn ($payment) => Money::toMinor($payment->amount));
            $totalMinor = Money::toMinor($invoice->total_amount);
            $balanceMinor = max(0, $totalMinor - $paidMinor);
            $invoice->update(['paid_amount' => Money::fromMinor($paidMinor), 'balance_amount' => Money::fromMinor($balanceMinor), 'payment_status' => $balanceMinor === 0 ? PaymentStatus::Paid->value : PaymentStatus::Partial->value]);
            if ($balanceMinor === 0 && $invoice->diningSession) {
                $invoice->diningSession()->update(['status' => 'closed', 'closed_at' => now()]);
                $closed = $invoice->diningSession->fresh();
                if ($closed) {
                    $this->closeMembers($closed, ['invoice_id' => $invoice->id]);
                }
            }
            ReportService::invalidateDashboard($invoice->hotel_id);

            return $invoice->fresh()->load('items', 'payments.receiver:id,name', 'diningSession.diningTable');
        });
    }

    public function attachGuestDetails(Invoice $invoice, array $guest): Invoice
    {
        return DB::transaction(function () use ($invoice, $guest) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === InvoiceStatus::Voided->value) {
                $this->invalid('invoice', 'Customer details cannot be added to a voided invoice.');
            }
            $snapshot = $this->guestSnapshot($invoice->diningSession, $guest, $invoice);
            $invoice->update($snapshot);
            if ($invoice->dining_session_id && ! empty($snapshot['customer_id'])) {
                $invoice->diningSession()?->update(['customer_id' => $snapshot['customer_id']]);
            }

            return $invoice->fresh()->load('customer', 'items', 'payments', 'diningSession.diningTable');
        });
    }

    /** @param array<string, mixed> $guest */
    private function guestSnapshot(?DiningSession $session, array $guest, ?Invoice $invoice = null): array
    {
        $blank = static fn (mixed $value): ?string => filled($value) ? trim((string) $value) : null;
        $customerId = $guest['customer_id'] ?? $session?->customer_id ?? $invoice?->customer_id;
            $customer = $customerId ? Customer::query()->find($customerId) : ($session?->customer ?? $invoice?->customer);

        return [
            'customer_id' => $customer?->id,
            'customer_name' => $blank($guest['customer_name'] ?? null) ?? $invoice?->customer_name ?? $customer?->name,
            'customer_phone' => $blank($guest['customer_phone'] ?? null) ?? $invoice?->customer_phone ?? $customer?->phone,
            'customer_email' => $blank($guest['customer_email'] ?? null) ?? $invoice?->customer_email ?? $customer?->email,
            'customer_gstin' => $blank($guest['customer_gstin'] ?? null) ?? $invoice?->customer_gstin ?? $customer?->gstin ?? $customer?->tax_number,
            'charged_to_user_id' => $guest['charged_to_user_id'] ?? $invoice?->charged_to_user_id,
        ];
    }

    private function recalculate(DiningSession $session): void
    {
        $session->refreshTotals();
    }

    private function absorbSession(DiningSession $other, DiningSession $into): void
    {
        $other->loadMissing('joinedTables:id', 'orders');
        $maxRound = (int) $into->orders()->lockForUpdate()->max('round_number');
        foreach ($other->orders()->orderBy('round_number')->orderBy('id')->get() as $order) {
            $maxRound++;
            $order->update(['dining_session_id' => $into->id, 'round_number' => $maxRound]);
        }
        $into->update(['guest_count' => max(1, (int) $into->guest_count + (int) $other->guest_count)]);
        $into->joinedTables()->syncWithoutDetaching($other->memberTableIds());
        $other->joinedTables()->detach();
        $other->update(['status' => 'closed', 'closed_at' => now(), 'closed_without_sale_reason' => 'Merged into another table']);
    }

    private function closeMembers(DiningSession $session, array $extra = []): void
    {
        $session->loadMissing('diningTable', 'waiter', 'joinedTables:id,name');
        $ids = $session->memberTableIds();
        $payload = RestaurantRealtime::payload($session, array_merge($extra, ['joined_table_ids' => $ids]));
        foreach ($ids as $tableId) {
            RestaurantUpdated::dispatch('table_closed', $session->hotel_id, $session->outlet_id, $tableId, $session->id, null, null, $session->waiter_id, null, $payload);
        }
        $session->joinedTables()->detach();
    }

    /** @return array<int, float|int> */
    private function ticketQuantities(Product $product, float $quantity, bool $direct): array
    {
        if ($direct || $product->fulfillment_mode !== FulfillmentMode::Kitchen->value) {
            return [$quantity];
        }

        $milli = Quantity::toMilli($quantity);
        if ($milli <= Quantity::SCALE || $milli % Quantity::SCALE !== 0) {
            return [$quantity];
        }

        $count = min(intdiv($milli, Quantity::SCALE), 99);

        return array_fill(0, $count, 1);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
