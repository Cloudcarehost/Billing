<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'hotel_id', 'user_id', 'created_by', 'updated_by', 'record_type', 'start_date', 'end_date',
        'leave_duration', 'amount', 'currency_code_snapshot', 'staff_name_snapshot',
        'role_name_snapshot', 'note',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
