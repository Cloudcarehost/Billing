<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = ['hotel_id', 'scope', 'key', 'status', 'request_hash', 'response', 'response_status', 'locked_at', 'expires_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'locked_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
