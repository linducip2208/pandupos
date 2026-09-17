<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_id', 'supplier_id', 'invoice_number', 'invoice_date', 'due_date', 'subtotal', 'discount', 'tax', 'shipping', 'total', 'paid', 'balance', 'status'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'shipping' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'balance' => 'decimal:2'];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
