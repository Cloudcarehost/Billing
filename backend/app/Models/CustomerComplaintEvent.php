<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerComplaintEvent extends Model
{
    use HasFactory;

    protected $fillable = ['complaint_id', 'actor_id', 'event_type', 'note', 'metadata', 'occurred_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class, 'complaint_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
