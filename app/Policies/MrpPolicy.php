<?php

namespace App\Policies;

use App\Models\MrpBom;
use App\Models\MrpWorkOrder;
use App\Models\User;

class MrpPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('mrp.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('mrp.manage') || $user->is_platform_admin;
    }

    public function manageBom(User $user, MrpBom $bom): bool
    {
        return $user->hasPermissionTo('mrp.manage') || $user->is_platform_admin;
    }

    public function manageOrder(User $user, MrpWorkOrder $order): bool
    {
        return $user->hasPermissionTo('mrp.manage') || $user->is_platform_admin;
    }
}
