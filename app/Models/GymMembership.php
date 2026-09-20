<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GymMembership extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'member_id', 'package_id', 'starts_on', 'ends_on',
        'visits_limit', 'visits_used', 'price', 'paid', 'balance', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date',
            'price' => 'decimal:2', 'paid' => 'decimal:2', 'balance' => 'decimal:2',
        ];
    }

    public function member()
    {
        return $this->belongsTo(GymMember::class, 'member_id');
    }

    public function package()
    {
        return $this->belongsTo(GymPackage::class, 'package_id');
    }

    public function attendances()
    {
        return $this->hasMany(GymAttendance::class, 'membership_id');
    }
}
