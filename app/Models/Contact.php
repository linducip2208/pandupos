<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'type', 'name', 'company', 'email', 'phone', 'address', 'tax_id', 'credit_limit', 'opening_balance'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'opening_balance' => 'decimal:2'];
    }

    public function customerLogin()
    {
        return $this->hasOne(CustomerLogin::class);
    }

    public function salesInvoices()
    {
        return $this->hasMany(SalesInvoice::class);
    }
}
