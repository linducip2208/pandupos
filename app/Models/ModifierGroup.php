<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'min_select', 'max_select'];

    public function options()
    {
        return $this->hasMany(Modifier::class, 'group_id');
    }
}
