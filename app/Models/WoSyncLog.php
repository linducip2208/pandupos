<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WoSyncLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'connection_id', 'direction', 'entity', 'external_id',
        'local_reference', 'status', 'message',
    ];

    public function connection()
    {
        return $this->belongsTo(WoConnection::class, 'connection_id');
    }
}
