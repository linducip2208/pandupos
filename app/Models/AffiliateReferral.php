<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateReferral extends Model
{
    protected $fillable = ['affiliate_user_id', 'referred_tenant_id', 'code'];

    public function affiliateUser()
    {
        return $this->belongsTo(User::class, 'affiliate_user_id');
    }

    public function referredTenant()
    {
        return $this->belongsTo(Tenant::class, 'referred_tenant_id');
    }
}
