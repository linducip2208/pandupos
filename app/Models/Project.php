<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use BelongsToTenant;

    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'contact_id', 'status', 'budget',
        'hourly_rate', 'starts_on', 'ends_on', 'manager_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2', 'hourly_rate' => 'decimal:2',
            'starts_on' => 'date', 'ends_on' => 'date',
        ];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function tasks()
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function timesheets()
    {
        return $this->hasMany(ProjectTimesheet::class);
    }

    public function expenses()
    {
        return $this->hasMany(ProjectExpense::class);
    }
}
