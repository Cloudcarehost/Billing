<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryStock;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockWastage;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\StockOperationsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends ApiController
{
    public function stocks(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $stocks = InventoryStock::query()->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))->with('outlet:id,name,code', 'product:id,name,sku,unit,track_inventory')->when($request->filled('outlet_id'), fn ($query) => $query->where('outlet_id', $request->integer('outlet_id')))->when($request->boolean('low_stock'), fn ($query) => $query->whereColumn('quantity', '<=', 'reorder_level'))->orderBy('product_id')->paginate(min($request->integer('per_page', 50), 100));

        return $this->paginated($stocks);
    }

    public function updateStock(Request $request, int $stock): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $stock = InventoryStock::query()->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))->findOrFail($stock);
        $data = $request->validate(['reorder_level' => ['required', 'numeric', 'min:0']]);
        $stock->update(['reorder_level' => $data['reorder_level']]);

        return $this->success($stock->fresh()->load('outlet:id,name,code', 'product:id,name,sku,unit,track_inventory'), 'Reorder level updated.');
    }

    public function movements(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');

        return $this->paginated(StockMovement::query()->whereHas('stock.outlet', fn ($query) => $query->where('hotel_id', $hotel->id))->with('stock.product:id,name,sku,unit', 'stock.outlet:id,name,code', 'creator:id,name')->when($request->filled('outlet_id'), fn ($query) => $query->whereHas('stock', fn ($stock) => $stock->where('outlet_id', $request->integer('outlet_id'))))->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))->latest('occurred_at')->paginate(min($request->integer('per_page', 50), 100)));
    }

    public function valuation(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $stocks = InventoryStock::query()->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))->when($request->filled('outlet_id'), fn ($query) => $query->where('outlet_id', $request->integer('outlet_id')))->selectRaw('COUNT(*) as stock_lines, COALESCE(SUM(quantity), 0) as quantity, COALESCE(SUM(quantity * average_cost), 0) as value')->first();

        return $this->success(['stock_lines' => (int) $stocks->stock_lines, 'quantity' => (string) $stocks->quantity, 'valuation' => (string) $stocks->value, 'currency' => $hotel->currency_code]);
    }

    public function receipt(Request $request, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['outlet_id' => ['required', 'integer'], 'supplier_id' => ['nullable', 'integer'], 'receipt_number' => ['nullable', 'string', 'max:60'], 'supplier_invoice_number' => ['nullable', 'string', 'max:80'], 'received_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit_cost' => ['required', 'numeric', 'min:0'], 'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $outlet = $this->outlet($hotel->id, $data['outlet_id']);
        if (! empty($data['supplier_id'])) {
            Supplier::query()->where('hotel_id', $hotel->id)->findOrFail($data['supplier_id']);
        }
        $receipt = DB::transaction(function () use ($hotel, $outlet, $data, $request, $stock, $audit) {
            $receipt = PurchaseReceipt::query()->create(['hotel_id' => $hotel->id, 'outlet_id' => $outlet->id, 'supplier_id' => $data['supplier_id'] ?? null, 'received_by' => $request->user()->id, 'receipt_number' => $data['receipt_number'] ?? 'RCV-'.now()->format('YmdHisv'), 'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null, 'received_on' => $data['received_on'] ?? today(), 'notes' => $data['notes'] ?? null]);
            $subtotalMinor = 0;
            $taxMinor = 0;
            foreach ($data['items'] as $item) {
                $product = $this->product($hotel->id, $item['product_id']);
                $priced = Money::taxedLine($item['unit_cost'], $item['quantity'], $item['tax_rate'] ?? 0, false);
                $receipt->items()->create(['product_id' => $product->id, 'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost'], 'tax_rate' => $item['tax_rate'] ?? 0, 'tax_amount' => $priced['tax'], 'line_total' => $priced['total']]);
                $stock->adjust($hotel, $outlet, $product, (float) $item['quantity'], $request->user(), 'stock_receipt', $receipt, 'Purchase receipt '.$receipt->receipt_number, (float) $item['unit_cost']);
                $subtotalMinor += Money::toMinor($priced['subtotal']);
                $taxMinor += Money::toMinor($priced['tax']);
            } $receipt->update(['subtotal' => Money::fromMinor($subtotalMinor), 'tax_amount' => Money::fromMinor($taxMinor), 'total_amount' => Money::fromMinor($subtotalMinor + $taxMinor)]);
            $audit->record($receipt, 'inventory.receipt', $request->user(), $hotel->id, $outlet->id);

            return $receipt;
        });

        return $this->success($receipt->load('supplier', 'outlet', 'items.product'), 'Stock receipt recorded.', 201);
    }

    public function adjustment(Request $request, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['outlet_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'], 'quantity_delta' => ['required', 'numeric', 'not_in:0'], 'reason' => ['required', 'string', 'max:1000'], 'unit_cost' => ['nullable', 'numeric', 'min:0']]);
        $outlet = $this->outlet($hotel->id, $data['outlet_id']);
        $product = $this->product($hotel->id, $data['product_id']);
        $result = $stock->adjust($hotel, $outlet, $product, (float) $data['quantity_delta'], $request->user(), 'manual_adjustment', null, $data['reason'], isset($data['unit_cost']) ? (float) $data['unit_cost'] : null);
        $audit->record($result, 'inventory.adjustment', $request->user(), $hotel->id, $outlet->id, ['reason' => $data['reason']]);

        return $this->success($result->load('product', 'outlet'), 'Stock adjusted.');
    }

    public function openingStock(Request $request, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['outlet_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'min:0'], 'unit_cost' => ['required', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $outlet = $this->outlet($hotel->id, $data['outlet_id']);
        $product = $this->product($hotel->id, $data['product_id']);
        $current = (float) (InventoryStock::query()->where('outlet_id', $outlet->id)->where('product_id', $product->id)->value('quantity') ?? 0);
        $result = $stock->adjust($hotel, $outlet, $product, (float) $data['quantity'] - $current, $request->user(), 'opening_stock', null, $data['reason'] ?? 'Opening stock', (float) $data['unit_cost']);
        $audit->record($result, 'inventory.opening_stock', $request->user(), $hotel->id, $outlet->id);

        return $this->success($result->load('product', 'outlet'), 'Opening stock set.');
    }

    public function wastage(Request $request, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['outlet_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:255'], 'occurred_at' => ['nullable', 'date']]);
        $outlet = $this->outlet($hotel->id, $data['outlet_id']);
        $product = $this->product($hotel->id, $data['product_id']);
        $wastage = StockWastage::query()->create(['hotel_id' => $hotel->id, 'outlet_id' => $outlet->id, 'product_id' => $product->id, 'recorded_by' => $request->user()->id, 'quantity' => $data['quantity'], 'unit_cost' => $product->cost_price, 'reason' => $data['reason'], 'occurred_at' => $data['occurred_at'] ?? now()]);
        $stock->adjust($hotel, $outlet, $product, -(float) $data['quantity'], $request->user(), 'wastage', $wastage, $data['reason']);
        $audit->record($wastage, 'inventory.wastage', $request->user(), $hotel->id, $outlet->id);

        return $this->success($wastage->load('product', 'outlet'), 'Wastage recorded.', 201);
    }

    public function transfer(Request $request, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['from_outlet_id' => ['required', 'integer'], 'to_outlet_id' => ['required', 'integer', 'different:from_outlet_id'], 'transfer_number' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'numeric', 'gt:0']]);
        $from = $this->outlet($hotel->id, $data['from_outlet_id']);
        $to = $this->outlet($hotel->id, $data['to_outlet_id']);
        $transfer = DB::transaction(function () use ($hotel, $from, $to, $data, $request, $stock, $audit) {
            $transfer = StockTransfer::query()->create(['hotel_id' => $hotel->id, 'from_outlet_id' => $from->id, 'to_outlet_id' => $to->id, 'created_by' => $request->user()->id, 'transfer_number' => $data['transfer_number'] ?? 'TRF-'.now()->format('YmdHisv'), 'status' => 'dispatched', 'dispatched_at' => now(), 'notes' => $data['notes'] ?? null]);
            foreach ($data['items'] as $line) {
                $product = $this->product($hotel->id, $line['product_id']);
                $cost = (float) ($product->stocks()->where('outlet_id', $from->id)->value('average_cost') ?? $product->cost_price);
                $transfer->items()->create(['product_id' => $product->id, 'quantity' => $line['quantity'], 'unit_cost' => $cost]);
                $stock->adjust($hotel, $from, $product, -(float) $line['quantity'], $request->user(), 'transfer_out', $transfer, 'Transfer '.$transfer->transfer_number);
            } $audit->record($transfer, 'inventory.transfer_dispatched', $request->user(), $hotel->id, $from->id, ['to_outlet_id' => $to->id]);

            return $transfer;
        });

        return $this->success($transfer->load('fromOutlet', 'toOutlet', 'items.product'), 'Stock transfer dispatched.', 201);
    }

    public function receiveTransfer(Request $request, int $transfer, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $transfer = StockTransfer::query()->where('hotel_id', $hotel->id)->with('items.product', 'toOutlet')->findOrFail($transfer);
        if ($transfer->status !== 'dispatched') {
            abort(422, 'Only dispatched transfers can be received.');
        } DB::transaction(function () use ($hotel, $transfer, $request, $stock, $audit) {
            foreach ($transfer->items as $item) {
                $stock->adjust($hotel, $transfer->toOutlet, $item->product, (float) $item->quantity, $request->user(), 'transfer_in', $transfer, 'Transfer '.$transfer->transfer_number, (float) $item->unit_cost);
            } $transfer->update(['status' => 'received', 'received_by' => $request->user()->id, 'received_at' => now()]);
            $audit->record($transfer, 'inventory.transfer_received', $request->user(), $hotel->id, $transfer->to_outlet_id);
        });

        return $this->success($transfer->fresh()->load('fromOutlet', 'toOutlet', 'items.product'), 'Transfer received.');
    }

    public function count(Request $request): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $data = $request->validate(['outlet_id' => ['required', 'integer'], 'notes' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.counted_quantity' => ['required', 'numeric', 'min:0']]);
        $outlet = $this->outlet($hotel->id, $data['outlet_id']);
        $count = DB::transaction(function () use ($hotel, $outlet, $data, $request) {
            $count = StockCount::query()->create(['hotel_id' => $hotel->id, 'outlet_id' => $outlet->id, 'created_by' => $request->user()->id, 'status' => 'submitted', 'counted_at' => now(), 'notes' => $data['notes'] ?? null]);
            foreach ($data['items'] as $line) {
                $product = $this->product($hotel->id, $line['product_id']);
                $stock = InventoryStock::query()->where('outlet_id', $outlet->id)->where('product_id', $product->id)->lockForUpdate()->first();
                $expected = (float) ($stock?->quantity ?? 0);
                $count->items()->create(['product_id' => $product->id, 'expected_quantity' => $expected, 'counted_quantity' => $line['counted_quantity'], 'variance_quantity' => (float) $line['counted_quantity'] - $expected, 'unit_cost' => $stock?->average_cost ?? $product->cost_price]);
            }

return $count;
        });

        return $this->success($count->load('outlet', 'items.product'), 'Stock count submitted for approval.', 201);
    }

    public function approveCount(Request $request, int $count, StockOperationsService $stock, AuditService $audit): JsonResponse
    {
        $hotel = $request->attributes->get('currentHotel');
        $count = StockCount::query()->where('hotel_id', $hotel->id)->with('outlet', 'items.product')->findOrFail($count);
        if ($count->status !== 'submitted') {
            abort(422, 'Only submitted stock counts can be approved.');
        } DB::transaction(function () use ($hotel, $count, $request, $stock, $audit) {
            foreach ($count->items as $item) {
                if ((float) $item->variance_quantity !== 0.0) {
                    $stock->adjust($hotel, $count->outlet, $item->product, (float) $item->variance_quantity, $request->user(), 'stock_count_adjustment', $count, 'Approved physical stock count', (float) $item->unit_cost);
                }
            } $count->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $audit->record($count, 'inventory.count_approved', $request->user(), $hotel->id, $count->outlet_id);
        });

        return $this->success($count->fresh()->load('outlet', 'items.product'), 'Stock count approved.');
    }

    private function outlet(int $hotelId, int $id): Outlet
    {
        return Outlet::query()->where('hotel_id', $hotelId)->findOrFail($id);
    }

    private function product(int $hotelId, int $id): Product
    {
        return Product::query()->where('hotel_id', $hotelId)->findOrFail($id);
    }
}
