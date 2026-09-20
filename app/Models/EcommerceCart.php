<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EcommerceCart extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'email', 'lines'];

    protected function casts(): array
    {
        return ['lines' => 'array'];
    }
}
