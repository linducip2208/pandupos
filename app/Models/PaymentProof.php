<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_login_id', 'sales_invoice_id', 'path', 'original_name',
        'mime_type', 'size', 'status', 'notes', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function customerLogin()
    {
        return $this->belongsTo(CustomerLogin::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
