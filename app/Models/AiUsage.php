<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'provider', 'feature', 'tokens_in', 'tokens_out'];
}
