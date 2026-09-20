<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProjectTask extends Model
{
    use BelongsToTenant;

    public const TODO = 'todo';

    public const DOING = 'doing';

    public const REVIEW = 'review';

    public const DONE = 'done';

    protected $fillable = [
        'tenant_id', 'project_id', 'title', 'description', 'assignee_id',
        'status', 'estimate_hours', 'due_on',
    ];

    protected function casts(): array
    {
        return ['estimate_hours' => 'decimal:2', 'due_on' => 'date'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
