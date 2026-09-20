<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    protected $fillable = ['tenant_id', 'period', 'status', 'approved_by', 'approved_at', 'paid_at', 'notes'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
