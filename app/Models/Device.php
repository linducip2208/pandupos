<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'device_id', 'name', 'last_sync_at'];

    protected function casts(): array
    {
        return ['last_sync_at' => 'datetime'];
    }
}
