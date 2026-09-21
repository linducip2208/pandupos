<?php

namespace App\Policies;

use App\Models\Cheque;
use App\Models\User;

class ChequePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('cheque.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('cheque.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, Cheque $cheque): bool
    {
        return $user->hasPermissionTo('cheque.manage') || $user->is_platform_admin;
    }
}
