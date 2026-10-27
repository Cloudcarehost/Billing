<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerComplaintUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'complaint_id', 'user_id', 'user_name_snapshot', 'role_name_snapshot', 'attribution', 'note',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class, 'complaint_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
