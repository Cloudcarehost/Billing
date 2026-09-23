<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandingCost extends Model
{
    protected $fillable = ['hotel_id', 'name', 'amount', 'pay_cycle', 'due_on', 'is_active'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
