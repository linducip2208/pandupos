<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KitchenTicket extends Model
{
    use BelongsToTenant;

    public const QUEUED = 'queued';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const SERVED = 'served';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'number', 'table_id', 'order_type', 'customer_name',
        'status', 'sales_invoice_id', 'total', 'accepted_at', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'accepted_at' => 'datetime'];
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function items()
    {
        return $this->hasMany(KitchenItem::class, 'ticket_id');
    }

    public function elapsedSeconds(): int
    {
        return abs(now()->diffInSeconds($this->created_at));
    }
}
