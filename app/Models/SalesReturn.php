<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sales_invoice_id', 'total', 'status'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class);
    }
}
