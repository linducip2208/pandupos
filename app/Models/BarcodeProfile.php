<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BarcodeProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'prefix', 'total_length', 'item_start', 'item_length',
        'value_start', 'value_length', 'value_type', 'decimal_places', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
