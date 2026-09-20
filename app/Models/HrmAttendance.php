<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HrmAttendance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'employee_id', 'worked_on', 'check_in', 'check_out',
        'work_hours', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['worked_on' => 'date', 'check_in' => 'datetime', 'check_out' => 'datetime', 'work_hours' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(HrmEmployee::class, 'employee_id');
    }
}
