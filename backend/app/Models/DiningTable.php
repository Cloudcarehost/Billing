<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningTable extends Model
{
    use HasFactory;

    protected $attributes = [
        'service_type' => 'dine_in',
    ];

    protected $fillable = ['outlet_id', 'name', 'code', 'capacity', 'sort_order', 'is_active', 'service_type', 'waiter_called_at'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean', 'waiter_called_at' => 'datetime'];
    }

    public function isParcel(): bool
    {
        return $this->service_type === 'parcel';
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DiningSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(DiningSession::class)->ofMany(
            ['opened_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->whereIn('status', ['occupied', 'pending_bill']),
        );
    }

    public function publicLink(): HasOne
    {
        return $this->hasOne(TablePublicLink::class);
    }
}
