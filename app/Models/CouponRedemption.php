<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{
    use BelongsToTenant;

    protected $fillable = ['coupon_id', 'tenant_id', 'subscription_id', 'discount_amount'];

    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2'];
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
