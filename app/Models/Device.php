<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'device_id', 'uuid', 'name', 'platform', 'last_sync_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_sync_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
