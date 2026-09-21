<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    use BelongsToTenant;

    public const AVAILABLE = 'available';

    public const OCCUPIED = 'occupied';

    public const RESERVED = 'reserved';

    protected $fillable = ['tenant_id', 'floor_id', 'code', 'name', 'seats', 'status'];

    public function floor()
    {
        return $this->belongsTo(RestaurantFloor::class, 'floor_id');
    }

    public function bookings()
    {
        return $this->hasMany(RestaurantBooking::class, 'table_id');
    }
}
