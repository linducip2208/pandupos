<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'short_name'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
