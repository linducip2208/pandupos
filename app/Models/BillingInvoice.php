<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BillingInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'invoice_no', 'subtotal',
        'discount', 'tax', 'total', 'currency', 'status', 'issued_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2',
            'tax' => 'decimal:2', 'total' => 'decimal:2',
            'issued_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function transactions()
    {
        return $this->hasMany(BillingTransaction::class);
    }

    public function items()
    {
        return $this->hasMany(BillingInvoiceItem::class);
    }
}
