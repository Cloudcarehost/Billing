<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyMoneyRegister extends Model
{
    protected $fillable = [
        'hotel_id', 'outlet_id', 'business_date', 'actual_online', 'actual_cash',
        'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'actual_online' => 'decimal:2',
            'actual_cash' => 'decimal:2',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
