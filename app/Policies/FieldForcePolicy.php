<?php

namespace App\Policies;

use App\Models\FfTask;
use App\Models\User;

class FieldForcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('fieldforce.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('fieldforce.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, FfTask $task): bool
    {
        return $user->hasPermissionTo('fieldforce.manage') || $user->is_platform_admin;
    }
}
