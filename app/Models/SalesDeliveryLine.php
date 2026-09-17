<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesDeliveryLine extends Model
{
    protected $fillable = ['sales_delivery_id', 'sales_order_line_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function delivery()
    {
        return $this->belongsTo(SalesDelivery::class, 'sales_delivery_id');
    }

    public function orderLine()
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }
}
