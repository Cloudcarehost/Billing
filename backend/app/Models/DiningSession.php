<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id', 'outlet_id', 'dining_table_id', 'waiter_id', 'customer_id',
        'guest_count', 'status', 'subtotal', 'discount_amount', 'discount_percent', 'tax_amount',
        'service_charge_amount', 'total_amount', 'opened_at', 'closed_at', 'closed_without_sale_by', 'closed_without_sale_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer', 'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'discount_percent' => 'decimal:4', 'tax_amount' => 'decimal:2',
            'service_charge_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
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

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function joinedTables(): BelongsToMany
    {
        return $this->belongsToMany(DiningTable::class, 'dining_session_tables')->withTimestamps();
    }

    public function memberTableIds(): array
    {
        $joined = $this->relationLoaded('joinedTables')
            ? $this->joinedTables->pluck('id')
            : $this->joinedTables()->pluck('dining_tables.id');

        return collect([$this->dining_table_id])->merge($joined)->unique()->values()->all();
    }

    public function displayName(): string
    {
        $this->loadMissing('diningTable:id,name', 'joinedTables:id,name');
        $names = collect([$this->diningTable?->name])->merge($this->joinedTables->pluck('name'))->filter()->unique()->values();

        return $names->implode(', ') ?: 'Table';
    }

    public static function occupying(int $tableId, bool $lock = false): ?self
    {
        $query = static::query()
            ->whereIn('status', ['occupied', 'pending_bill'])
            ->where(function ($builder) use ($tableId) {
                $builder->where('dining_table_id', $tableId)
                    ->orWhereExists(function ($sub) use ($tableId) {
                        $sub->from('dining_session_tables')
                            ->whereColumn('dining_session_tables.dining_session_id', 'dining_sessions.id')
                            ->where('dining_session_tables.dining_table_id', $tableId);
                    });
            });
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function refreshTotals(): void
    {
        $items = $this->orders()->with('items')->get()->flatMap->items->where('status', '!=', 'cancelled');
        $subtotalMinor = $items->sum(fn ($item) => Money::toMinor($item->line_subtotal));
        $taxMinor = $items->sum(fn ($item) => Money::toMinor($item->tax_amount));
        $serviceMinor = Money::toMinor($this->service_charge_amount);
        $maxMinor = $subtotalMinor + $taxMinor + $serviceMinor;
        if ($this->discount_percent !== null) {
            $discountMinor = (int) round($maxMinor * ((float) $this->discount_percent) / 100, 0, PHP_ROUND_HALF_UP);
        } else {
            $discountMinor = Money::toMinor($this->discount_amount);
        }
        $discountMinor = min(max(0, $discountMinor), $maxMinor);
        $this->update([
            'subtotal' => Money::fromMinor($subtotalMinor),
            'tax_amount' => Money::fromMinor($taxMinor),
            'discount_amount' => Money::fromMinor($discountMinor),
            'total_amount' => Money::fromMinor($maxMinor - $discountMinor),
        ]);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', 'issued'),
        );
    }
}
