<?php

namespace App\Policies;

use App\Models\HmsPatient;
use App\Models\User;

class HmsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('hms.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('hms.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, HmsPatient $patient): bool
    {
        return $user->hasPermissionTo('hms.manage') || $user->is_platform_admin;
    }
}
