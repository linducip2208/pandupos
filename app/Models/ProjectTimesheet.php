<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProjectTimesheet extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'task_id', 'user_id', 'worked_on', 'hours', 'notes'];

    protected function casts(): array
    {
        return ['worked_on' => 'date', 'hours' => 'decimal:2'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
