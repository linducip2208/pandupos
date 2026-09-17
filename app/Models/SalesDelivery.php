<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sales_order_id', 'delivery_no', 'status', 'delivery_date', 'tracking_reference', 'delivered_by'];

    protected function casts(): array
    {
        return ['delivery_date' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(SalesDeliveryLine::class);
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
