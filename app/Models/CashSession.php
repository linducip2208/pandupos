<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'register_id', 'opened_by', 'opening_amount', 'closing_amount', 'status', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return ['opening_amount' => 'decimal:2', 'closing_amount' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
