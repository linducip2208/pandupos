<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HrmLeave extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const TYPES = ['annual', 'sick', 'unpaid'];

    protected $fillable = [
        'tenant_id', 'employee_id', 'type', 'starts_on', 'ends_on',
        'days', 'reason', 'status', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(HrmEmployee::class, 'employee_id');
    }
}
