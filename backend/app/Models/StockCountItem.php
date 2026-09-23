<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    protected $fillable = ['stock_count_id', 'product_id', 'expected_quantity', 'counted_quantity', 'variance_quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3', 'variance_quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
