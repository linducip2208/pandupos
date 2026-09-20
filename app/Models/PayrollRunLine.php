<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PayrollRunLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'employee_id', 'base_salary', 'allowances_total',
        'gross', 'tax', 'deductions_total', 'net', 'present_days', 'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2', 'allowances_total' => 'decimal:2',
            'gross' => 'decimal:2', 'tax' => 'decimal:2', 'deductions_total' => 'decimal:2',
            'net' => 'decimal:2', 'breakdown' => 'array',
        ];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrmEmployee::class, 'employee_id');
    }
}
