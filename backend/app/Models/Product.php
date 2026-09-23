<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $attributes = [
        'track_inventory' => false,
        'is_active' => true,
        'is_quick' => false,
        'fulfillment_mode' => 'direct',
        'cost_price' => 0,
    ];

    protected $fillable = [
        'hotel_id', 'category_id', 'kitchen_station_id', 'fulfillment_mode', 'name', 'short_description', 'sku', 'barcode', 'hsn_code', 'unit', 'serving_size',
        'selling_price', 'cost_price', 'tax_rate', 'price_includes_tax',
        'track_inventory', 'is_active', 'is_quick',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2', 'cost_price' => 'decimal:2',
            'tax_rate' => 'decimal:4', 'price_includes_tax' => 'boolean',
            'track_inventory' => 'boolean', 'is_active' => 'boolean', 'is_quick' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class);
    }
}
