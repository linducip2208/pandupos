<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HmsInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'number', 'patient_id', 'appointment_id', 'consultation_fee',
        'pharmacy_total', 'discount', 'total', 'paid', 'balance', 'status',
    ];

    protected function casts(): array
    {
        return [
            'consultation_fee' => 'decimal:2', 'pharmacy_total' => 'decimal:2',
            'discount' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    public function patient()
    {
        return $this->belongsTo(HmsPatient::class, 'patient_id');
    }

    public function lines()
    {
        return $this->hasMany(HmsInvoiceLine::class);
    }
}
