<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'legal_name', 'tax_number', 'gstin', 'state_code', 'currency_code',
        'timezone', 'business_day_starts_at', 'last_ended_business_date', 'phone', 'email', 'address',
        'is_active', 'allow_negative_stock', 'inventory_deduction_rule',
        'alert_sound_kitchen', 'alert_sound_ready', 'alert_sound_call', 'alert_sound_billing',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'last_ended_business_date' => 'date',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role_id', 'is_active', 'joined_at', 'salary_amount', 'pay_cycle'])
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function diningSessions(): HasMany
    {
        return $this->hasMany(DiningSession::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function standingCosts(): HasMany
    {
        return $this->hasMany(StandingCost::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
