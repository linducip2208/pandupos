<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Modifier extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'group_id', 'name', 'price_delta', 'is_active'];

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function group()
    {
        return $this->belongsTo(ModifierGroup::class, 'group_id');
    }
}
