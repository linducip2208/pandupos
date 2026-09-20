<?php

namespace App\Livewire;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\PriceResolverService;
use App\Services\SaleService;
use App\Services\UnitConversionService;
use App\Support\TenantContext;
use Livewire\Component;

class PosKasir extends Component
{
    public string $search = '';

    /** @var array<int, array{variant_id:int,name:string,base_price:float,price:float,qty:float,discount:float,tax_rate:float,tax_method:string,base_unit_id:int,unit_id:int,unit_name:string,factor:float,inventory_batch_id:?int,serial_number_ids:array<int,int>}> */
    public array $cart = [];

    /** @var array<int, array{method:string,amount:float}> */
    public array $payments = [['method' => 'cash', 'amount' => 0]];

    public ?string $lastInvoiceNo = null;

    public float $lastChange = 0;

    public ?int $customerId = null;

    public ?int $cashSessionId = null;

    /** @var array<string,array> held carts keyed by label */
    public array $held = [];

    public function mount(): void
    {
        $this->cashSessionId = CashSession::query()->where('status', 'open')->where('opened_by', auth()->id())->value('id');
    }

    public function addToCart(int $variantId, string $name, int $baseUnitId, string $unitName, float $taxRate, string $taxMethod, PriceResolverService $prices): void
    {
        foreach ($this->cart as &$row) {
            if ($row['variant_id'] === $variantId) {
                $row['qty'] += 1;

                return;
            }
        }
        $tenantId = TenantContext::idOrFail();
        $branchId = $this->branchId($tenantId);
        $groupId = $this->customerId ? Contact::query()->find($this->customerId)?->customer_group_id : null;
        $resolved = $prices->resolveDetails($tenantId, $variantId, 1, $branchId, $groupId);
        $this->cart[] = [
            'variant_id' => $variantId, 'name' => $name, 'base_price' => $resolved['price'], 'price' => $resolved['price'], 'qty' => 1,
            'base_unit_id' => $baseUnitId, 'unit_id' => $baseUnitId, 'unit_name' => $unitName, 'factor' => 1,
            'price_source' => $resolved['source'], 'discount' => 0, 'tax_rate' => $taxRate, 'tax_method' => $taxMethod,
            'inventory_batch_id' => null, 'serial_number_ids' => [],
        ];
    }

    public function changeUnit(int $index, int $unitId, UnitConversionService $conversions): void
    {
        abort_unless(isset($this->cart[$index]), 404);
        $row = $this->cart[$index];
        $factor = $conversions->convert(TenantContext::idOrFail(), 1, $unitId, (int) $row['base_unit_id']);
        $unit = Unit::query()->findOrFail($unitId);
        $this->cart[$index]['unit_id'] = $unitId;
        $this->cart[$index]['unit_name'] = $unit->short_name;
        $this->cart[$index]['factor'] = $factor;
        $this->cart[$index]['price'] = round((float) $row['base_price'] * $factor, 2);
    }

    public function selectBatch(int $index, ?int $batchId): void
    {
        abort_unless(isset($this->cart[$index]), 404);
        if ($batchId === null) {
            $this->cart[$index]['inventory_batch_id'] = null;

            return;
        }
        $tenantId = TenantContext::idOrFail();
        $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id');
        $batch = InventoryBatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $this->cart[$index]['variant_id'])->findOrFail($batchId);
        abort_if($batch->expires_at?->isBefore(today()), 422, 'Batch kedaluwarsa tidak dapat dipilih.');
        $this->cart[$index]['inventory_batch_id'] = $batch->id;
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
        return round(collect($this->cart)->sum(fn ($row) => $row['qty'] * $row['price'] - (float) ($row['discount'] ?? 0)) + $this->tax, 2);
    }

    public function getTaxProperty(): float
    {
        return round(collect($this->cart)->sum(function (array $row) {
            if (($row['tax_method'] ?? 'exclusive') !== 'exclusive') {
                return 0;
            }

            return max(0, $row['qty'] * $row['price'] - (float) ($row['discount'] ?? 0)) * ((float) ($row['tax_rate'] ?? 0) / 100);
        }), 2);
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
            'cashSessionId' => 'required|integer',
        ]);

        $user = auth()->user();
        $tenantId = TenantContext::idOrFail();
        abort_unless($user->memberships()->withoutGlobalScopes()->where('tenant_id', $tenantId)->exists() || $user->is_platform_admin, 403);
        abort_unless($user->can('pos.sale.create'), 403);
        abort_unless(CashSession::query()->whereKey($this->cashSessionId)->where('status', 'open')->where('opened_by', $user->id)->exists(), 422, 'Pilih sesi register Anda yang masih terbuka.');
        $branchId = $this->branchId($tenantId);
        $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id');

        // Cash tendering: a single cash line may exceed the total; the drawer
        // nets exactly the invoice total and the excess is customer change.
        // Split payments must still balance exactly (enforced by SaleService).
        $change = 0.0;
        if (count($this->payments) === 1 && ($this->payments[0]['method'] ?? null) === 'cash') {
            $tendered = round((float) ($this->payments[0]['amount'] ?? 0), 2);
            if ($tendered > $this->total) {
                $change = round($tendered - $this->total, 2);
                $this->payments[0]['amount'] = $this->total;
            }
        }

        $invoice = $sales->checkout(
            $tenantId, $branchId, $warehouseId, $this->customerId,
            collect($this->cart)->map(fn ($r) => [
                'variant_id' => $r['variant_id'], 'quantity' => $r['qty'] * $r['factor'], 'unit_price' => $r['base_price'],
                'discount' => (float) ($r['discount'] ?? 0),
                'inventory_batch_id' => $r['inventory_batch_id'] ?? null,
                'serial_number_ids' => $r['serial_number_ids'] ?? [],
            ])->all(),
            $this->payments,
            'web-'.uniqid(),
            $this->cashSessionId,
            $this->tax,
        );

        $this->lastInvoiceNo = $invoice->invoice_no;
        $this->lastChange = $change > 0 ? $change : round(collect($this->payments)->sum(fn ($p) => (float) $p['amount']) - $this->total, 2);
        $this->cart = [];
        $this->payments = [['method' => 'cash', 'amount' => 0]];
        session()->flash('status', 'Terjual: '.$invoice->invoice_no.($this->lastChange > 0 ? " | Kembali: {$this->lastChange}" : ''));
    }

    public function render()
    {
        TenantContext::idOrFail();
        $products = Product::with(['variants', 'unit'])
            ->when($this->search, function ($query) {
                $term = trim($this->search);
                $query->where(function ($catalog) use ($term) {
                    $catalog->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', $term)
                        ->orWhereHas('variants', fn ($variants) => $variants
                            ->where('sku', 'like', "%{$term}%")
                            ->orWhere('barcode', $term));
                });
            })
            ->limit(24)->get();

        return view('livewire.pos-kasir', [
            'products' => $products,
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'customers' => Contact::query()->with('customerGroup')->whereIn('type', ['customer', 'both'])->orderBy('name')->get(),
            'batches' => InventoryBatch::query()->where('warehouse_id', Warehouse::query()->value('id'))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->get(),
            'serials' => SerialNumber::query()->with('warehouseLocation')->where('warehouse_id', Warehouse::query()->value('id'))
                ->whereIn('status', ['available', 'returned'])->orderBy('serial_number')->get(),
            'cashSessions' => CashSession::query()->with('register')->where('status', 'open')->where('opened_by', auth()->id())->orderBy('opened_at')->get(),
        ]);
    }

    private function branchId(int $tenantId): int
    {
        return (int) (auth()->user()->memberships()->where('tenant_id', $tenantId)->first()?->branch_ids[0]
            ?? Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id'));
    }
}
