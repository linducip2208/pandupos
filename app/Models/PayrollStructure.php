<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PayrollStructure extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'employee_id', 'base_salary', 'allowances', 'deductions', 'income_tax_rate'];

    protected function casts(): array
    {
        return ['base_salary' => 'decimal:2', 'allowances' => 'array', 'deductions' => 'array', 'income_tax_rate' => 'decimal:4'];
    }

    public function employee()
    {
        return $this->belongsTo(HrmEmployee::class, 'employee_id');
    }
}
