<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'contact_id', 'quotation_no', 'status', 'quotation_date', 'valid_until', 'subtotal', 'discount', 'tax', 'total', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['quotation_date' => 'date', 'valid_until' => 'date', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(SalesQuotationLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function proformas()
    {
        return $this->hasMany(ProformaInvoice::class);
    }
}
