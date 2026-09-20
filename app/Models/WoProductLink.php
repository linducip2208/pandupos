<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WoProductLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'connection_id', 'local_variant_id', 'woo_product_id'];

    public function connection()
    {
        return $this->belongsTo(WoConnection::class, 'connection_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'local_variant_id');
    }
}
