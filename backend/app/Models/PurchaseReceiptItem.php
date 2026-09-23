<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptItem extends Model
{
    protected $fillable = ['purchase_receipt_id', 'product_id', 'quantity', 'unit_cost', 'tax_rate', 'tax_amount', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
