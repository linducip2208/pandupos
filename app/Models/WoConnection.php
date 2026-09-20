<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WoConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'store_url', 'consumer_key', 'consumer_secret',
        'status', 'last_sync_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['last_sync_at' => 'datetime'];
    }

    public function links()
    {
        return $this->hasMany(WoProductLink::class, 'connection_id');
    }

    public function logs()
    {
        return $this->hasMany(WoSyncLog::class, 'connection_id');
    }
}
