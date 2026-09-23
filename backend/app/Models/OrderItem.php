<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'product_id', 'kitchen_station_id', 'fulfillment_mode', 'item_name', 'sku', 'unit',
        'quantity', 'unit_price', 'unit_cost', 'discount_amount', 'tax_rate',
        'tax_amount', 'line_subtotal', 'line_total', 'status', 'kitchen_note',
        'preparing_at', 'ready_at', 'served_at', 'cancelled_at', 'preparing_by', 'ready_by', 'served_by', 'cancelled_by', 'cancel_reason', 'inventory_deducted',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:2',
            'line_subtotal' => 'decimal:2', 'line_total' => 'decimal:2',
            'preparing_at' => 'datetime', 'ready_at' => 'datetime', 'served_at' => 'datetime',
            'cancelled_at' => 'datetime', 'inventory_deducted' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function invoiceItem(): HasOne
    {
        return $this->hasOne(InvoiceItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
