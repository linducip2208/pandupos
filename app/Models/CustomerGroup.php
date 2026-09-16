<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'default_discount_percent'];

    protected function casts(): array
    {
        return ['default_discount_percent' => 'decimal:4'];
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function priceLists()
    {
        return $this->hasMany(PriceList::class);
    }
}
