<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outlet extends Model
{
    use HasFactory;

    protected $attributes = [
        'order_flow' => 'kitchen',
    ];

    protected $fillable = ['hotel_id', 'name', 'code', 'invoice_prefix', 'address', 'is_active', 'order_flow'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function kitchenStations(): HasMany
    {
        return $this->hasMany(KitchenStation::class);
    }

    public function diningSessions(): HasMany
    {
        return $this->hasMany(DiningSession::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoiceSequences(): HasMany
    {
        return $this->hasMany(InvoiceSequence::class);
    }
}
