<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EcommerceOrder extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'number', 'contact_id', 'email', 'recipient', 'phone',
        'address', 'city', 'postal_code', 'shipping_method', 'shipping_fee',
        'tracking_number', 'status', 'subtotal', 'discount', 'total', 'paid',
        'payment_method', 'paid_at', 'shipped_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_fee' => 'decimal:2', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2',
            'total' => 'decimal:2', 'paid' => 'decimal:2',
            'paid_at' => 'datetime', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime',
        ];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines()
    {
        return $this->hasMany(EcommerceOrderLine::class);
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->paid, 2);
    }
}
