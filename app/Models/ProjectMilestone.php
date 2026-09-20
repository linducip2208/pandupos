<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProjectMilestone extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'title', 'due_on', 'done_at'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
