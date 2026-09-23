<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\InventoryStock;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockOperationsService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function adjust(Hotel $hotel, Outlet $outlet, Product $product, float $delta, User $user, string $type, ?Model $reference = null, ?string $reason = null, ?float $unitCost = null): InventoryStock
    {
        return DB::transaction(function () use ($hotel, $outlet, $product, $delta, $user, $type, $reference, $reason, $unitCost) {
            $stock = $this->inventory->lockStock($outlet->id, $product);
            $nextMilli = Quantity::toMilli($stock->quantity) + Quantity::toMilli($delta);
            if ($nextMilli < 0 && ! $hotel->allow_negative_stock) {
                throw ValidationException::withMessages(['quantity' => ["Insufficient stock for {$product->name}."]]);
            }
            $next = Quantity::fromMilli($nextMilli);
            $averageCost = $unitCost ?? (float) $stock->average_cost ?: (float) $product->cost_price;
            if ($delta > 0 && $unitCost !== null) {
                $currentQty = (float) $stock->quantity;
                $averageCost = (float) $next > 0 ? round((($currentQty * (float) $stock->average_cost) + ($delta * $unitCost)) / (float) $next, 2) : $unitCost;
            }
            $stock->update(['quantity' => $next, 'average_cost' => $averageCost]);
            StockMovement::query()->create(['inventory_stock_id' => $stock->id, 'created_by' => $user->id, 'type' => $type, 'quantity_delta' => Quantity::fromMilli(Quantity::toMilli($delta)), 'balance_after' => $next, 'unit_cost' => $unitCost ?? $stock->average_cost, 'reference_type' => $reference ? $reference::class : null, 'reference_id' => $reference?->getKey(), 'reason' => $reason, 'occurred_at' => now()]);
            ReportService::invalidateDashboard($hotel->id);

            return $stock->fresh();
        });
    }
}
