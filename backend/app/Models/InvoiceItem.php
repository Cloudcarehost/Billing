<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'product_id', 'order_item_id', 'item_name', 'category_name', 'sku', 'hsn_code', 'unit', 'serving_size', 'quantity',
        'unit_price', 'unit_cost', 'discount_amount', 'tax_rate', 'tax_type', 'tax_amount',
        'line_subtotal', 'line_total', 'cgst_amount', 'sgst_amount', 'igst_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:2',
            'line_subtotal' => 'decimal:2', 'line_total' => 'decimal:2', 'cgst_amount' => 'decimal:2', 'sgst_amount' => 'decimal:2', 'igst_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
