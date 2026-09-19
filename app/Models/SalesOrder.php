<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'warehouse_id', 'contact_id', 'sales_quotation_id', 'order_no', 'status', 'order_date', 'total', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'total' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function deliveries()
    {
        return $this->hasMany(SalesDelivery::class);
    }

    public function invoice()
    {
        return $this->hasOne(SalesInvoice::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function quotation()
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
