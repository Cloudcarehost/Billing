<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TablePublicLink extends Model
{
    protected $fillable = ['dining_table_id', 'token_hash', 'token_encrypted', 'is_active', 'rotated_at'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['token_encrypted' => 'encrypted', 'is_active' => 'boolean', 'rotated_at' => 'datetime'];
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }
}
