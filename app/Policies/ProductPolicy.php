<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        // Server-side gate: catalog browsing requires an explicit grant.
        // Mere tenant membership is not sufficient.
        return $user->hasPermissionTo('inventory.view') || $user->hasPermissionTo('products.manage') || $user->is_platform_admin;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->current_tenant_id === $product->tenant_id || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('products.manage') || $user->is_platform_admin;
    }

    public function update(User $user, Product $product): bool
    {
        return $this->view($user, $product) && ($user->hasPermissionTo('products.manage') || $user->is_platform_admin);
    }
}
