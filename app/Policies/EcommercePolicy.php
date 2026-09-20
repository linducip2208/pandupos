<?php

namespace App\Policies;

use App\Models\EcommerceOrder;
use App\Models\User;

class EcommercePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ecommerce.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ecommerce.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, EcommerceOrder $order): bool
    {
        return $user->hasPermissionTo('ecommerce.manage') || $user->is_platform_admin;
    }
}
