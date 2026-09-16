<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BillingTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'billing_invoice_id', 'gateway', 'gateway_ref',
        'amount', 'currency', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array'];
    }

    public function invoice()
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
