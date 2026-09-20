<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GymPackage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'duration_days', 'price', 'visits_limit', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
