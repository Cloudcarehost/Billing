<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_stock_id', 'created_by', 'type', 'quantity_delta',
        'balance_after', 'unit_cost', 'reference_type', 'reference_id',
        'reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:3', 'balance_after' => 'decimal:3',
            'unit_cost' => 'decimal:2', 'occurred_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
