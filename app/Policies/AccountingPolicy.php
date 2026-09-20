<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;

class AccountingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('accounting.view') || $user->is_platform_admin;
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo('accounting.view') || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('accounting.manage') || $user->is_platform_admin;
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('accounting.manage') || $user->is_platform_admin;
    }

    public function void(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('accounting.manage') || $user->is_platform_admin;
    }

    public function closePeriod(User $user): bool
    {
        return $user->hasPermissionTo('accounting.manage') || $user->is_platform_admin;
    }

    public function manageAccount(User $user, Account $account): bool
    {
        return $user->hasPermissionTo('accounting.manage') || $user->is_platform_admin;
    }
}
