<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'register_id', 'opened_by', 'closed_by', 'opening_amount', 'closing_amount', 'expected_amount', 'variance_amount', 'denomination_counts', 'status', 'opened_at', 'closed_at', 'closing_notes'];

    protected function casts(): array
    {
        return ['opening_amount' => 'decimal:2', 'closing_amount' => 'decimal:2', 'expected_amount' => 'decimal:2', 'variance_amount' => 'decimal:2', 'denomination_counts' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements()
    {
        return $this->hasMany(CashSessionMovement::class);
    }

    public function invoices()
    {
        return $this->hasMany(SalesInvoice::class);
    }
}
