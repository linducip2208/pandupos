<div class="row row-cards">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><input id="pos-search" class="form-control" placeholder="Scan barcode / ketik nama + Enter (F2 fokus)" wire:model.live="search" autofocus></div>
            <div class="card-body row g-2">
                @foreach($products as $p)
                    @foreach($p->variants as $v)
                        <div class="col-6 col-md-4">
                            <button class="btn btn-outline-primary w-100 h-100 py-3" wire:click="addToCart({{ $v->id }}, '{{ addslashes($p->name) }}', {{ $v->sell_price }})">
                                <div class="fw-bold">{{ $p->name }}</div>
                                <div class="text-secondary">Rp {{ number_format($v->sell_price, 0, ',', '.') }}</div>
                            </button>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Keranjang • Total Rp {{ number_format($this->total, 0, ',', '.') }}</h3></div>
            <div class="card-body p-0">
                <table class="table table-vcenter mb-0">
                    @foreach($cart as $i => $row)
                        <tr>
                            <td>{{ $row['name'] }}<div class="text-secondary">Rp {{ number_format($row['price'], 0, ',', '.') }}</div></td>
                            <td style="width:130px">
                                <div class="input-group input-group-sm">
                                    <button class="btn btn-outline-secondary" wire:click="dec({{ $i }})">−</button>
                                    <input class="form-control text-center" value="{{ $row['qty'] }}" readonly>
                                    <button class="btn btn-outline-secondary" wire:click="inc({{ $i }})">+</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <div class="card-footer">
                @foreach($payments as $i => $pay)
                    <div class="input-group mb-2">
                        <select class="form-select" wire:model="payments.{{ $i }}.method">
                            <option value="cash">Cash</option><option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option><option value="ewallet">E-Wallet</option><option value="card">Card</option>
                        </select>
                        <input class="form-control" type="number" wire:model="payments.{{ $i }}.amount" placeholder="Nominal">
                    </div>
                @endforeach
                <button class="btn btn-primary btn-lg w-100" wire:click="checkout">Bayar (F9)</button>
            </div>
        </div>
    </div>
</div>
@script
<script>
document.addEventListener('keydown', e => {
  if (e.key === 'F2') { e.preventDefault(); document.getElementById('pos-search')?.focus(); }
});
</script>
@endscript
