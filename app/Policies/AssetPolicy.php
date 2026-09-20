<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('asset.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('asset.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, Asset $asset): bool
    {
        return $user->hasPermissionTo('asset.manage') || $user->is_platform_admin;
    }
}
