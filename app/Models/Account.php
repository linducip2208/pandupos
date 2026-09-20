<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToTenant;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'type', 'parent_id', 'is_system', 'is_cash', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_cash' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}
