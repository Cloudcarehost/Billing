<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerComplaintItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'complaint_id', 'order_id', 'order_item_id', 'product_id', 'ticket_number_snapshot',
        'item_name_snapshot', 'item_status_snapshot', 'service_route_snapshot',
        'ordered_quantity', 'complained_quantity',
    ];

    protected function casts(): array
    {
        return ['ordered_quantity' => 'decimal:3', 'complained_quantity' => 'decimal:3'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class, 'complaint_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
