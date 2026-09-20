<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ZatcaDocument;

class ZatcaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('zatca.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('zatca.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, ZatcaDocument $document): bool
    {
        return $user->hasPermissionTo('zatca.manage') || $user->is_platform_admin;
    }
}
