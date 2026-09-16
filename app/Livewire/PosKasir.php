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

    public ?string $lastInvoiceNo = null;

    public float $lastChange = 0;

    /** @var array<string,array> held carts keyed by label */
    public array $held = [];

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

    public function getPaidProperty(): float
    {
        return collect($this->payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0));
    }

    public function getChangeProperty(): float
    {
        return round($this->paid - $this->total, 2);
    }

    public function holdSale(string $label = 'HOLD-1'): void
    {
        $this->held[$label] = ['cart' => $this->cart, 'payments' => $this->payments, 'held_at' => now()->toDateTimeString()];
        $this->cart = [];
        $this->payments = [['method' => 'cash', 'amount' => 0]];
        session()->flash('status', "Sale held: {$label}");
    }

    public function resumeSale(string $label): void
    {
        if (! isset($this->held[$label])) {
            return;
        }
        $this->cart = $this->held[$label]['cart'] ?? [];
        $this->payments = $this->held[$label]['payments'] ?? [['method' => 'cash', 'amount' => 0]];
        unset($this->held[$label]);
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

        $this->lastInvoiceNo = $invoice->invoice_no;
        $this->lastChange = round(collect($this->payments)->sum(fn ($p) => (float) $p['amount']) - $this->total, 2);
        $this->cart = [];
        $this->payments = [['method' => 'cash', 'amount' => 0]];
        session()->flash('status', 'Terjual: '.$invoice->invoice_no.($this->lastChange > 0 ? " | Kembali: {$this->lastChange}" : ''));
    }

    public function render()
    {
        $products = Product::with('variants')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->limit(24)->get();

        return view('livewire.pos-kasir', ['products' => $products]);
    }
}
