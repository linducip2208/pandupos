<?php

namespace App\Policies;

use App\Models\RepairOrder;
use App\Models\User;

class RepairPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('repair.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('repair.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, RepairOrder $order): bool
    {
        return $user->hasPermissionTo('repair.manage') || $user->is_platform_admin;
    }
}
