<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RestaurantFloor extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'sort_order'];

    public function tables()
    {
        return $this->hasMany(RestaurantTable::class, 'floor_id');
    }
}
