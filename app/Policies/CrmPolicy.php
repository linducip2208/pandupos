<?php

namespace App\Policies;

use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Models\User;

class CrmPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('crm.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('crm.manage') || $user->is_platform_admin;
    }

    public function manageLead(User $user, CrmLead $lead): bool
    {
        return $user->hasPermissionTo('crm.manage') || $user->is_platform_admin;
    }

    public function manageOpportunity(User $user, CrmOpportunity $opportunity): bool
    {
        return $user->hasPermissionTo('crm.manage') || $user->is_platform_admin;
    }
}
