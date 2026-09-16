<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sales_invoice_id', 'method', 'amount', 'reference'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
