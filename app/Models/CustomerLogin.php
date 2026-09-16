<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Foundation\Auth\User as Authenticatable;

class CustomerLogin extends Authenticatable
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'contact_id', 'email', 'password', 'is_active', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function paymentProofs()
    {
        return $this->hasMany(PaymentProof::class);
    }
}
