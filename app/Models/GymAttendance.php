<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GymAttendance extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'membership_id', 'member_id', 'trainer_id', 'checked_in_at'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function membership()
    {
        return $this->belongsTo(GymMembership::class, 'membership_id');
    }

    public function member()
    {
        return $this->belongsTo(GymMember::class, 'member_id');
    }

    public function trainer()
    {
        return $this->belongsTo(GymTrainer::class, 'trainer_id');
    }
}
