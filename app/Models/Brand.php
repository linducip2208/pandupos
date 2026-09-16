<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
