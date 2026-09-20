<?php

namespace App\Policies;

use App\Models\HrmEmployee;
use App\Models\User;

class HrmPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('hrm.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('hrm.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, HrmEmployee $employee): bool
    {
        return $user->hasPermissionTo('hrm.manage') || $user->is_platform_admin;
    }
}
