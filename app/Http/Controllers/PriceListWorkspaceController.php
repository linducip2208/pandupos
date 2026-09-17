<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Services\PriceResolverService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PriceListWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        return view('price-lists.index', [
            'priceLists' => PriceList::query()->with(['branch', 'customerGroup', 'items.variant.product'])->latest()->get(),
            'branches' => Branch::query()->orderBy('name')->get(), 'groups' => CustomerGroup::query()->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product')->where('is_active', true)->orderBy('sku')->get(),
        ]);
    }

    public function store(Request $request, PriceResolverService $resolver): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $this->validated($request, $tenantId);
        $list = DB::transaction(function () use ($data) {
            $item = $data['item'];
            unset($data['item']);
            $list = PriceList::create($data);
            $list->items()->create($item);

            return $list->load('items');
        });
        $resolver->auditChange($tenantId, $list->id, null, $list->toArray(), $request->user()->id);

        return back()->with('status', 'Daftar harga dibuat.');
    }

    public function update(Request $request, PriceList $priceList, PriceResolverService $resolver): RedirectResponse
    {
        $this->authorize('create', Product::class);
        abort_unless($priceList->tenant_id === TenantContext::idOrFail(), 404);
        $data = $this->validated($request, $priceList->tenant_id);
        $before = $priceList->load('items')->toArray();
        DB::transaction(function () use ($priceList, $data) {
            $item = $data['item'];
            unset($data['item']);
            $priceList->update($data);
            $priceList->items()->updateOrCreate(['product_variant_id' => $item['product_variant_id'], 'minimum_quantity' => $item['minimum_quantity']], ['price' => $item['price']]);
        });
        $resolver->auditChange($priceList->tenant_id, $priceList->id, $before, $priceList->fresh('items')->toArray(), $request->user()->id);

        return back()->with('status', 'Daftar harga diperbarui.');
    }

    public function archive(Request $request, PriceList $priceList, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        abort_unless($priceList->tenant_id === TenantContext::idOrFail(), 404);
        $before = $priceList->toArray();
        $priceList->update(['is_active' => false]);
        $audit->log($priceList->tenant_id, $request->user()->id, 'price_list.archived', PriceList::class, $priceList->id, $before, $priceList->fresh()->toArray());

        return back()->with('status', 'Daftar harga dinonaktifkan.');
    }

    private function validated(Request $request, int $tenantId): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'], 'scope' => ['required', Rule::in(['retail', 'wholesale', 'member', 'vip', 'branch', 'customer_group', 'promotion'])],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'customer_group_id' => ['nullable', Rule::exists('customer_groups', 'id')->where('tenant_id', $tenantId)],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['required', 'integer', 'min:0', 'max:100000'], 'is_active' => ['nullable', 'boolean'],
            'item.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'item.price' => ['required', 'numeric', 'min:0'], 'item.minimum_quantity' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
