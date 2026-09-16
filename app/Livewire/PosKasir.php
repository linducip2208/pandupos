<?php

namespace App\Livewire;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\SaleService;
use App\Support\TenantContext;
use Livewire\Component;

class PosKasir extends Component
{
    public string $search = '';

    /** @var array<int, array{variant_id:int,name:string,price:float,qty:float}> */
    public array $cart = [];

    /** @var array<int, array{method:string,amount:float}> */
    public array $payments = [['method' => 'cash', 'amount' => 0]];

    public function addToCart(int $variantId, string $name, float $price): void
    {
        foreach ($this->cart as &$row) {
            if ($row['variant_id'] === $variantId) {
                $row['qty'] += 1;

                return;
            }
        }
        $this->cart[] = ['variant_id' => $variantId, 'name' => $name, 'price' => $price, 'qty' => 1];
    }

    public function inc(int $i): void
    {
        $this->cart[$i]['qty'] += 1;
    }

    public function dec(int $i): void
    {
        $this->cart[$i]['qty'] = max(0, $this->cart[$i]['qty'] - 1);
        if ($this->cart[$i]['qty'] === 0) {
            unset($this->cart[$i]);
            $this->cart = array_values($this->cart);
        }
    }

    public function getTotalProperty(): float
    {
        return collect($this->cart)->sum(fn ($r) => $r['qty'] * $r['price']);
    }

    public function checkout(SaleService $sales): void
    {
        $this->validate([
            'cart' => 'required|array|min:1',
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0',
        ]);

        $user = auth()->user();
        $tenantId = TenantContext::id() ?? $user->current_tenant_id;
        $branchId = $user->memberships()->where('tenant_id', $tenantId)->first()?->branch_ids[0]
            ?? Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id');
        $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id');

        $invoice = $sales->checkout(
            $tenantId, $branchId, $warehouseId, null,
            collect($this->cart)->map(fn ($r) => [
                'variant_id' => $r['variant_id'], 'quantity' => $r['qty'], 'unit_price' => $r['price'],
            ])->all(),
            $this->payments,
            'web-'.uniqid(),
        );

        $this->cart = [];
        $this->payments = [['method' => 'cash', 'amount' => 0]];
        session()->flash('status', 'Terjual: '.$invoice->invoice_no);
    }

    public function render()
    {
        $products = Product::with('variants')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->limit(24)->get();

        return view('livewire.pos-kasir', ['products' => $products]);
    }
}
