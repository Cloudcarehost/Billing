<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id', 'outlet_id', 'customer_id', 'dining_session_id', 'created_by', 'charged_to_user_id', 'voided_by',
        'invoice_number', 'status', 'payment_status', 'business_date',
        'billed_at', 'voided_at', 'customer_name', 'customer_phone', 'customer_email', 'customer_gstin',
        'subtotal', 'discount_amount', 'tax_amount', 'service_charge_amount',
        'rounding_amount', 'total_amount', 'paid_amount', 'balance_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply',
        'notes', 'void_reason', 'reprint_count', 'last_reprinted_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date', 'billed_at' => 'datetime', 'voided_at' => 'datetime', 'last_reprinted_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'service_charge_amount' => 'decimal:2',
            'rounding_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'balance_amount' => 'decimal:2', 'cgst_amount' => 'decimal:2', 'sgst_amount' => 'decimal:2', 'igst_amount' => 'decimal:2',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function diningSession(): BelongsTo
    {
        return $this->belongsTo(DiningSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function chargedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'charged_to_user_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
