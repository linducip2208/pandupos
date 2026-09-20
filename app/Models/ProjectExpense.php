<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProjectExpense extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'description', 'category', 'amount', 'spent_on', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'spent_on' => 'date'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
