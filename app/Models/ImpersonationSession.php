<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImpersonationSession extends Model
{
    protected $fillable = [
        'platform_user_id', 'tenant_id', 'target_user_id',
        'started_at', 'ended_at', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
