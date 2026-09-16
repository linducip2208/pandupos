<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServerChangeLog extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'entity_type', 'entity_id', 'operation', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
