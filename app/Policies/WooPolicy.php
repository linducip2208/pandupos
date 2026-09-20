<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WoConnection;

class WooPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('woocommerce.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('woocommerce.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, WoConnection $connection): bool
    {
        return $user->hasPermissionTo('woocommerce.manage') || $user->is_platform_admin;
    }
}
