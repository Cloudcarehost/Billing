<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id', 'outlet_id', 'dining_table_id', 'waiter_id', 'customer_id',
        'guest_count', 'status', 'subtotal', 'discount_amount', 'tax_amount',
        'service_charge_amount', 'total_amount', 'opened_at', 'closed_at', 'closed_without_sale_by', 'closed_without_sale_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer', 'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'service_charge_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', 'issued'),
        );
    }
}
