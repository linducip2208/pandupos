<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const VOID = 'void';

    protected $fillable = [
        'tenant_id', 'entry_no', 'entry_date', 'description',
        'source_type', 'source_id', 'status', 'posted_at', 'voided_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() > 0 && abs($this->totalDebit() - $this->totalCredit()) < 0.01;
    }
}
