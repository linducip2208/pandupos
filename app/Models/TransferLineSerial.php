<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferLineSerial extends Model
{
    protected $fillable = ['transfer_line_id', 'serial_number_id'];

    public function transferLine()
    {
        return $this->belongsTo(TransferLine::class);
    }

    public function serialNumber()
    {
        return $this->belongsTo(SerialNumber::class);
    }
}
