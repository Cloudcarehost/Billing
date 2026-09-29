<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['hotel_id', 'name', 'slug', 'is_active', 'print_on_bill', 'sort_order'];

    protected $attributes = [
        'is_active' => true,
        'print_on_bill' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'print_on_bill' => 'boolean', 'sort_order' => 'integer'];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
