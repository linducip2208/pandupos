<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HmsDoctor extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'specialization', 'consultation_fee', 'user_id', 'is_active'];

    protected function casts(): array
    {
        return ['consultation_fee' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function appointments()
    {
        return $this->hasMany(HmsAppointment::class, 'doctor_id');
    }
}
