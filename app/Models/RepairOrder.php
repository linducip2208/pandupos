<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RepairOrder extends Model
{
    use BelongsToTenant;

    public const RECEIVED = 'received';

    public const DIAGNOSED = 'diagnosed';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING_PARTS = 'waiting_parts';

    public const READY = 'ready';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'number', 'contact_id', 'device_brand', 'device_model',
        'device_identifier', 'complaint', 'diagnosis', 'status', 'warranty',
        'labor_cost', 'parts_cost', 'discount', 'total', 'paid', 'payment_method',
        'warehouse_id', 'technician_id', 'promised_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'warranty' => 'boolean',
            'labor_cost' => 'decimal:2', 'parts_cost' => 'decimal:2',
            'discount' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2',
            'promised_at' => 'datetime', 'delivered_at' => 'datetime',
        ];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function parts()
    {
        return $this->hasMany(RepairOrderPart::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->paid, 2);
    }
}
