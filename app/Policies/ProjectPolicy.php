<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('project.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('project.manage') || $user->is_platform_admin;
    }

    public function manage(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('project.manage') || $user->is_platform_admin;
    }
}
