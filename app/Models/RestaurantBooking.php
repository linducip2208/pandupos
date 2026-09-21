<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RestaurantBooking extends Model
{
    use BelongsToTenant;

    public const BOOKED = 'booked';

    public const SEATED = 'seated';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    protected $fillable = ['tenant_id', 'table_id', 'customer_name', 'phone', 'starts_at', 'party_size', 'status', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
