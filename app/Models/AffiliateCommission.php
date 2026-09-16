<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    protected $fillable = ['affiliate_user_id', 'referred_tenant_id', 'subscription_id', 'sale_amount', 'commission_percent', 'commission_amount', 'status'];

    protected function casts(): array
    {
        return ['sale_amount' => 'decimal:2', 'commission_percent' => 'decimal:2', 'commission_amount' => 'decimal:2'];
    }

    public function affiliateUser()
    {
        return $this->belongsTo(User::class, 'affiliate_user_id');
    }

    public function referredTenant()
    {
        return $this->belongsTo(Tenant::class, 'referred_tenant_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
