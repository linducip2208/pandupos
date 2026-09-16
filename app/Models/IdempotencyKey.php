<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'fingerprint', 'response', 'locked_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'locked_at' => 'datetime'];
    }
}
