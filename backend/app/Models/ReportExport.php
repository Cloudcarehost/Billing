<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    protected $fillable = ['hotel_id', 'requested_by', 'type', 'format', 'status', 'filters', 'disk', 'path', 'expires_at', 'error_message'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime'];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
