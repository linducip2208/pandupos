<?php

namespace App\Policies;

use App\Models\AiProviderConfig;
use App\Models\User;

class AiPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ai.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ai.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, AiProviderConfig $config): bool
    {
        return $user->hasPermissionTo('ai.manage') || $user->is_platform_admin;
    }
}
