<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payroll.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payroll.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, PayrollRun $run): bool
    {
        return $user->hasPermissionTo('payroll.manage') || $user->is_platform_admin;
    }
}
