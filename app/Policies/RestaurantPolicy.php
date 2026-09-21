<?php

namespace App\Policies;

use App\Models\KitchenTicket;
use App\Models\User;

class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('restaurant.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('restaurant.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, KitchenTicket $ticket): bool
    {
        return $user->hasPermissionTo('restaurant.manage') || $user->is_platform_admin;
    }
}
