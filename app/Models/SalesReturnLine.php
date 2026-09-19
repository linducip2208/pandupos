<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnLine extends Model
{
    protected $fillable = ['sales_return_id', 'sales_line_id', 'quantity', 'unit_price', 'inventory_batch_id', 'serial_number_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2'];
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesLine()
    {
        return $this->belongsTo(SalesLine::class);
    }
}
