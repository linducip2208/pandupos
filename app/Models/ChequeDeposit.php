<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ChequeDeposit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'number', 'bank_name', 'deposited_on', 'status', 'created_by', 'notes'];

    protected function casts(): array
    {
        return ['deposited_on' => 'date'];
    }

    public function cheques()
    {
        return $this->hasMany(Cheque::class, 'deposit_id');
    }
}
