<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HmsAppointment extends Model
{
    use BelongsToTenant;

    public const SCHEDULED = 'scheduled';

    public const CHECKED_IN = 'checked_in';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    protected $fillable = ['tenant_id', 'patient_id', 'doctor_id', 'scheduled_at', 'duration_minutes', 'status', 'notes'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function patient()
    {
        return $this->belongsTo(HmsPatient::class, 'patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(HmsDoctor::class, 'doctor_id');
    }

    public function record()
    {
        return $this->hasOne(HmsRecord::class, 'appointment_id');
    }
}
