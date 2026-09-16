<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingInvoiceItem extends Model
{
    protected $fillable = ['billing_invoice_id', 'description', 'quantity', 'unit_price', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
