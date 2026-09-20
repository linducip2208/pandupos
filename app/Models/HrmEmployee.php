<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HrmEmployee extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const TERMINATED = 'terminated';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'department_id', 'position',
        'user_id', 'join_date', 'status', 'phone', 'address',
    ];

    protected function casts(): array
    {
        return ['join_date' => 'date'];
    }

    public function department()
    {
        return $this->belongsTo(HrmDepartment::class, 'department_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attendances()
    {
        return $this->hasMany(HrmAttendance::class, 'employee_id');
    }

    public function leaves()
    {
        return $this->hasMany(HrmLeave::class, 'employee_id');
    }
}
