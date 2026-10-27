<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerComplaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id', 'outlet_id', 'dining_session_id', 'invoice_id', 'customer_id', 'reported_table_id',
        'created_by', 'resolved_by', 'complaint_number', 'business_date', 'status', 'reason_code',
        'customer_statement', 'internal_note', 'responsibility_area', 'outcome', 'resolution_type',
        'resolution_note', 'table_name_snapshot', 'invoice_number_snapshot', 'customer_name_snapshot',
        'reported_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function diningSession(): BelongsTo
    {
        return $this->belongsTo(DiningSession::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reportedTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'reported_table_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerComplaintItem::class, 'complaint_id');
    }

    public function involvedUsers(): HasMany
    {
        return $this->hasMany(CustomerComplaintUser::class, 'complaint_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerComplaintEvent::class, 'complaint_id')->orderBy('occurred_at')->orderBy('id');
    }
}
