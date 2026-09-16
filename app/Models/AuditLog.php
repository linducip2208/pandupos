<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'actor_id', 'action', 'subject_type', 'subject_id',
        'before', 'after', 'ip', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
