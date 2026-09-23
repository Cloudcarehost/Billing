<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'dining_session_id', 'created_by', 'ticket_number', 'status', 'round_number',
        'sent_at', 'started_at', 'ready_at', 'served_at',
        'cancelled_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer', 'sent_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime', 'served_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function diningSession(): BelongsTo
    {
        return $this->belongsTo(DiningSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
