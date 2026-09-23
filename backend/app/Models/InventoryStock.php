<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryStock extends Model
{
    use HasFactory;

    protected $fillable = ['outlet_id', 'product_id', 'quantity', 'reorder_level', 'average_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'reorder_level' => 'decimal:3', 'average_cost' => 'decimal:2'];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
