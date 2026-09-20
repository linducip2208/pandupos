<?php

namespace App\Policies;

use App\Models\GymMembership;
use App\Models\User;

class GymPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('gym.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('gym.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, GymMembership $membership): bool
    {
        return $user->hasPermissionTo('gym.manage') || $user->is_platform_admin;
    }
}
