<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HmsPatient extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'birth_date', 'gender', 'phone', 'address', 'blood_type'];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function appointments()
    {
        return $this->hasMany(HmsAppointment::class, 'patient_id');
    }

    public function records()
    {
        return $this->hasMany(HmsRecord::class, 'patient_id');
    }
}
