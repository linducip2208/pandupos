<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FfTask extends Model
{
    use BelongsToTenant;

    public const ASSIGNED = 'assigned';

    public const EN_ROUTE = 'en_route';

    public const CHECKED_IN = 'checked_in';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'title', 'description', 'assignee_id', 'contact_id',
        'address', 'planned_lat', 'planned_lng', 'status', 'due_on',
    ];

    protected function casts(): array
    {
        return [
            'planned_lat' => 'decimal:7', 'planned_lng' => 'decimal:7',
            'due_on' => 'date',
        ];
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function visits()
    {
        return $this->hasMany(FfVisit::class, 'task_id');
    }
}
