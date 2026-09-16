<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid', 'tenant_id', 'branch_id', 'warehouse_id', 'contact_id',
        'invoice_no', 'status', 'payment_status', 'subtotal', 'discount', 'tax', 'total', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(SalesLine::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
}
