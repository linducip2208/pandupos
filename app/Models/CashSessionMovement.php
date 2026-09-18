<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CashSessionMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'cash_session_id', 'created_by', 'type', 'amount', 'reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function session()
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
