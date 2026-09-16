<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.view') || $user->is_platform_admin;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->current_tenant_id === $product->tenant_id || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.adjust') || $user->is_platform_admin
            || $user->memberships()->exists();
    }
}
