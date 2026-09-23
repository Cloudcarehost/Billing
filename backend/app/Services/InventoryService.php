<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\StockWastage;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Database\QueryException;

class InventoryService
{
    public function ensureProductStocks(Product $product): void
    {
        if (! $product->track_inventory) {
            return;
        }
        Outlet::query()->where('hotel_id', $product->hotel_id)->pluck('id')->each(function (int $outletId) use ($product): void {
            $this->lockStock($outletId, $product);
        });
    }

    public function ensureOutletStocks(Outlet $outlet): void
    {
        Product::query()->where('hotel_id', $outlet->hotel_id)->where('track_inventory', true)->get()
            ->each(fn (Product $product) => $this->lockStock($outlet->id, $product));
    }

    public function lockStock(int $outletId, Product $product): InventoryStock
    {
        $stock = InventoryStock::query()->where('outlet_id', $outletId)->where('product_id', $product->id)->lockForUpdate()->first();
        if ($stock) {
            return $stock;
        }
        try {
            return InventoryStock::query()->create([
                'outlet_id' => $outletId,
                'product_id' => $product->id,
                'quantity' => 0,
                'average_cost' => $product->cost_price ?? 0,
            ]);
        } catch (QueryException $exception) {
            $stock = InventoryStock::query()->where('outlet_id', $outletId)->where('product_id', $product->id)->lockForUpdate()->first();
            if ($stock) {
                return $stock;
            }

            throw $exception;
        }
    }

    public function deductForItem(OrderItem $item, User $user): void
    {
        $this->adjust($item, $user, -1, 'order_deduction', null);
    }

    public function reverseForCancellation(OrderItem $item, User $user, bool $wastage = false): void
    {
        $this->adjust($item, $user, $wastage ? -1 : 1, $wastage ? 'wastage' : 'order_reversal', $wastage ? 'Prepared item cancelled' : null, ! $wastage);
    }

    private function adjust(OrderItem $item, User $user, int $direction, string $type, ?string $reason, bool $changeStock = true): void
    {
        if (! $item->product_id || $direction === 0) {
            return;
        }
        $product = $item->product;
        if (! $product?->track_inventory) {
            return;
        }
        $recipe = Recipe::query()->with('items.ingredient')->where('product_id', $product->id)->where('is_active', true)->first();
        if ($recipe && (float) $recipe->yield_quantity > 0) {
            foreach ($recipe->items as $component) {
                if ($component->ingredient?->track_inventory) {
                    $qty = ((float) $component->quantity / (float) $recipe->yield_quantity) * (float) $item->quantity;
                    $this->adjustProduct($item, $component->ingredient, $qty, $user, $direction, $type, $reason, $changeStock);
                }
            }

            return;
        }
        $this->adjustProduct($item, $product, (float) $item->quantity, $user, $direction, $type, $reason, $changeStock);
    }

    private function adjustProduct(OrderItem $item, Product $product, float $quantity, User $user, int $direction, string $type, ?string $reason, bool $changeStock = true): void
    {
        $session = $item->order->diningSession;
        $stock = $this->lockStock($session->outlet_id, $product);
        $deltaMilli = Quantity::toMilli($quantity) * $direction;
        $currentMilli = Quantity::toMilli($stock->quantity);
        $nextMilli = $changeStock ? $currentMilli + $deltaMilli : $currentMilli;
        if ($changeStock) {
            $stock->update(['quantity' => Quantity::fromMilli($nextMilli)]);
        }
        StockMovement::query()->create([
            'inventory_stock_id' => $stock->id,
            'created_by' => $user->id,
            'type' => $type,
            'quantity_delta' => Quantity::fromMilli($deltaMilli),
            'balance_after' => Quantity::fromMilli($nextMilli),
            'unit_cost' => $product->cost_price,
            'reference_type' => OrderItem::class,
            'reference_id' => $item->id,
            'reason' => $reason,
        ]);
        if ($type === 'wastage') {
            StockWastage::query()->create([
                'hotel_id' => $session->hotel_id,
                'outlet_id' => $session->outlet_id,
                'product_id' => $product->id,
                'recorded_by' => $user->id,
                'quantity' => Quantity::fromMilli(abs($deltaMilli)),
                'unit_cost' => $product->cost_price,
                'reason' => $reason ?? 'Prepared item cancelled',
                'occurred_at' => now(),
            ]);
        }
    }
}
