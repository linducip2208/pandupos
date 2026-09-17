<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProformaInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'contact_id', 'sales_quotation_id', 'proforma_no', 'status', 'issue_date', 'due_date', 'subtotal', 'discount', 'tax', 'total', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(ProformaInvoiceLine::class);
    }

    public function quotation()
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
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
}
