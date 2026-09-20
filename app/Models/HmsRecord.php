<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HmsRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'appointment_id', 'patient_id', 'doctor_id', 'diagnosis', 'prescription', 'notes'];

    public function appointment()
    {
        return $this->belongsTo(HmsAppointment::class, 'appointment_id');
    }

    public function patient()
    {
        return $this->belongsTo(HmsPatient::class, 'patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(HmsDoctor::class, 'doctor_id');
    }
}
