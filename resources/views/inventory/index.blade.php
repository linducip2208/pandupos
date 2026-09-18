@extends('layouts.tabler')

@section('title', 'Kontrol Persediaan')
@section('page-title', 'Kontrol Persediaan')
@section('page-subtitle', 'Lokasi, reservasi, transfer, penyesuaian, dan stock count dalam satu ruang kerja')

@section('content')
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Data belum dapat diproses.</strong>
            <ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row row-cards mb-4">
        @foreach ([
            ['label' => 'Lokasi aktif', 'value' => $locations->where('is_active', true)->count(), 'tone' => 'primary'],
            ['label' => 'Reservasi aktif', 'value' => $reservations->where('status', 'active')->count(), 'tone' => 'azure'],
            ['label' => 'Transfer berjalan', 'value' => $transfers->whereNotIn('status', ['received', 'cancelled'])->count(), 'tone' => 'orange'],
            ['label' => 'Hitung belum posting', 'value' => $counts->where('status', '!=', 'posted')->count(), 'tone' => 'purple'],
        ] as $stat)
            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
                <div class="text-secondary small text-uppercase fw-bold">{{ $stat['label'] }}</div>
                <div class="display-6 fw-bold text-{{ $stat['tone'] }}">{{ $stat['value'] }}</div>
            </div></div></div>
        @endforeach
    </div>

    <div class="row row-cards">
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="card-title">Lokasi fisik gudang</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('inventory.locations.store') }}" class="row g-3 mb-4">
                        @csrf
                        <div class="col-12 col-md-6"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required><option value="">Pilih gudang</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                        <div class="col-12 col-md-6"><label class="form-label">Kode lokasi</label><input name="code" class="form-control" required maxlength="50" placeholder="Z1-R02-S03-B04"></div>
                        @foreach (['zone' => 'Zona', 'rack' => 'Rak', 'shelf' => 'Shelf', 'bin' => 'Bin'] as $name => $label)
                            <div class="col-6 col-md-3"><label class="form-label">{{ $label }}</label><input name="{{ $name }}" class="form-control" maxlength="80"></div>
                        @endforeach
                        <div class="col-12"><button class="btn btn-primary w-100 w-md-auto">Tambah lokasi</button></div>
                    </form>
                    <div class="table-responsive"><table class="table table-vcenter">
                        <thead><tr><th>Kode</th><th>Gudang</th><th>Posisi</th></tr></thead>
                        <tbody>@forelse ($locations as $location)<tr><td class="fw-semibold">{{ $location->code }}</td><td>{{ $location->warehouse?->name }}</td><td>{{ collect([$location->zone, $location->rack, $location->shelf, $location->bin])->filter()->join(' / ') ?: '—' }}</td></tr>@empty<tr><td colspan="3" class="text-center text-secondary py-4">Belum ada lokasi fisik.</td></tr>@endforelse</tbody>
                    </table></div>
                    @if ($locations->isNotEmpty())
                        <div class="mt-3 vstack gap-2">
                            @foreach ($locations as $location)
                                <details class="border rounded p-2"><summary class="fw-semibold">Kelola {{ $location->code }} · {{ $location->warehouse?->name }}</summary>
                                    <form method="POST" action="{{ route('inventory.locations.update', $location) }}" class="row g-2 mt-1">@csrf @method('PUT')
                                        <div class="col-12"><input name="code" value="{{ $location->code }}" class="form-control form-control-sm" required></div>
                                        @foreach (['zone', 'rack', 'shelf', 'bin'] as $field)<div class="col-6"><input name="{{ $field }}" value="{{ $location->$field }}" class="form-control form-control-sm" placeholder="{{ ucfirst($field) }}"></div>@endforeach
                                        <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Simpan</button>@if ($location->is_active)<button form="deactivate-location-{{ $location->id }}" class="btn btn-sm btn-outline-danger">Nonaktifkan</button>@endif</div>
                                    </form>
                                    @if ($location->is_active)<form id="deactivate-location-{{ $location->id }}" method="POST" action="{{ route('inventory.locations.deactivate', $location) }}">@csrf</form>@endif
                                </details>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="card-title">Reservasi stok</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('inventory.reservations.store') }}" class="row g-3 mb-4">
                        @csrf
                        <div class="col-12 col-md-6"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                        <div class="col-12 col-md-6"><label class="form-label">Varian</label><select name="product_variant_id" class="form-select" required>@foreach ($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} · {{ $variant->product?->name }}</option>@endforeach</select></div>
                        <div class="col-12 col-md-6"><label class="form-label">Lokasi fisik (opsional)</label><select name="warehouse_location_id" class="form-select"><option value="">Seluruh gudang</option>@foreach ($locations->where('is_active', true) as $location)<option value="{{ $location->id }}">{{ $location->warehouse?->name }} · {{ $location->code }}</option>@endforeach</select></div>
                        <div class="col-12 col-md-6"><label class="form-label">Batch (opsional)</label><select name="inventory_batch_id" class="form-select"><option value="">Otomatis / tanpa batch</option>@foreach ($batches as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Jumlah</label><input name="quantity" type="number" min="0.001" step="0.001" class="form-control" required></div>
                        <div class="col-6"><label class="form-label">Sumber</label><input name="source_type" class="form-control" value="manual_hold" required></div>
                        <div class="col-12"><button class="btn btn-primary w-100 w-md-auto">Reservasi stok</button></div>
                    </form>
                    <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Produk</th><th>Gudang</th><th>Qty</th><th>Status</th><th></th></tr></thead><tbody>
                        @forelse ($reservations as $reservation)<tr><td>{{ $reservation->variant?->sku }}</td><td>{{ $reservation->warehouse?->name }}</td><td>{{ number_format((float) $reservation->quantity, 3, ',', '.') }}</td><td><span class="badge">{{ $reservation->status }}</span></td><td>@if ($reservation->status === 'active')<form method="POST" action="{{ route('inventory.reservations.release', $reservation) }}">@csrf<button class="btn btn-sm btn-outline-danger">Lepas</button></form>@endif</td></tr>
                        @empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada reservasi.</td></tr>@endforelse
                    </tbody></table></div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Transfer antar gudang</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('inventory.transfers.store') }}" class="row g-3 mb-4">
                        @csrf
                        <div class="col-12 col-md-3"><label class="form-label">Dari gudang</label><select name="from_warehouse_id" class="form-select" required>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                        <div class="col-12 col-md-3"><label class="form-label">Ke gudang</label><select name="to_warehouse_id" class="form-select" required>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                        <div class="col-12"><label class="form-label">Baris transfer</label><div id="transfer-lines" class="vstack gap-2"><div class="row g-2 transfer-line"><div class="col-12 col-lg-3"><select name="lines[0][product_variant_id]" class="form-select" required>@foreach ($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} · {{ $variant->product?->name }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][source_warehouse_location_id]" class="form-select" aria-label="Rak/bin sumber"><option value="">Rak/bin sumber</option>@foreach ($locations->where('is_active', true) as $location)<option value="{{ $location->id }}" data-warehouse="{{ $location->warehouse_id }}">{{ $location->code }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][destination_warehouse_location_id]" class="form-select" aria-label="Rak/bin tujuan"><option value="">Rak/bin tujuan</option>@foreach ($locations->where('is_active', true) as $location)<option value="{{ $location->id }}" data-warehouse="{{ $location->warehouse_id }}">{{ $location->code }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][inventory_batch_id]" class="form-select"><option value="">Tanpa batch</option>@foreach ($batches as $batch)<option value="{{ $batch->id }}" data-warehouse="{{ $batch->warehouse_id }}" data-variant="{{ $batch->product_variant_id }}">{{ $batch->batch_number }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][serial_number_ids][]" class="form-select" multiple size="1" aria-label="Serial opsional">@foreach ($serials as $serial)<option value="{{ $serial->id }}" data-warehouse="{{ $serial->warehouse_id }}" data-variant="{{ $serial->product_variant_id }}">{{ $serial->serial_number }}</option>@endforeach</select></div><div class="col-12 col-lg-1"><input name="lines[0][quantity]" type="number" min="0.001" step="0.001" class="form-control" placeholder="Qty" required></div></div></div><div class="form-text">Pilih batch atau serial hanya dari gudang/varian sumber. Rak/bin berlaku untuk transfer nonserial; jumlah serial harus sama dengan qty baris.</div><button type="button" id="add-transfer-line" class="btn btn-sm btn-outline-secondary mt-2">+ Tambah baris</button></div>
                        <div class="col-12 col-md-8"><label class="form-label">Catatan (opsional)</label><input name="notes" class="form-control" maxlength="1000" placeholder="Alasan atau referensi transfer"></div>
                        <div class="col-12 col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100">Buat permintaan</button></div>
                    </form>
                    <div class="text-secondary small mb-3">Pembuat tidak dapat menyetujui permintaan transfernya sendiri. Stok tujuan baru bertambah saat penerimaan dicatat.</div>
                    <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>ID</th><th>Rute</th><th>Item</th><th>Pembuat</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                        @forelse ($transfers as $transfer)<tr><td>#{{ $transfer->id }}</td><td>{{ $transfer->fromWarehouse?->name }} → {{ $transfer->toWarehouse?->name }}</td><td>@foreach ($transfer->lines as $line)<div>{{ $line->variant?->sku }} · {{ number_format((float) $line->received_quantity, 3) }}/{{ number_format((float) $line->quantity, 3) }}</div>@endforeach</td><td>{{ $transfer->requester?->name ?? 'Riwayat lama' }}</td><td><span class="badge">{{ str_replace('_', ' ', $transfer->status) }}</span></td><td><div class="d-flex flex-wrap gap-2">
                            @foreach (['draft' => ['approve' => 'Setujui', 'cancel' => 'Batal'], 'approved' => ['ship' => 'Kirim', 'cancel' => 'Batal'], 'shipped' => ['transit' => 'Dalam perjalanan']] as $status => $actions)
                                @if ($transfer->status === $status) @foreach ($actions as $action => $label)<form method="POST" action="{{ route('inventory.transfers.action', [$transfer, $action]) }}">@csrf<button class="btn btn-sm btn-outline-primary">{{ $label }}</button></form>@endforeach @endif
                            @endforeach
                            @if (in_array($transfer->status, ['shipped', 'in_transit', 'partial_received'], true))
                                <form method="POST" action="{{ route('inventory.transfers.receive', $transfer) }}" class="d-flex flex-wrap gap-1">@csrf @foreach ($transfer->lines as $line)<input name="quantities[{{ $line->id }}]" type="number" step="0.001" min="0.001" max="{{ (float) $line->quantity - (float) $line->received_quantity }}" class="form-control form-control-sm" style="width:7rem" placeholder="Terima">@endforeach<button class="btn btn-sm btn-success">Terima</button></form>
                            @endif
                        </div></td></tr>@empty<tr><td colspan="6" class="text-center text-secondary py-4">Belum ada transfer.</td></tr>@endforelse
                    </tbody></table></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card h-100"><div class="card-header"><h2 class="card-title">Penyesuaian stok</h2></div><div class="card-body">
                <form method="POST" action="{{ route('inventory.adjustments.store') }}" class="row g-3 mb-4">@csrf
                    <div class="col-12 col-md-6"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-6"><label class="form-label">Alasan</label><select name="reason" class="form-select" required>@foreach (['damage' => 'Rusak', 'expired' => 'Kedaluwarsa', 'loss' => 'Hilang', 'count_correction' => 'Koreksi hitung', 'opening_correction' => 'Koreksi awal', 'other' => 'Lainnya'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Baris penyesuaian</label><div id="adjustment-lines" class="vstack gap-2"><div class="row g-2 adjustment-line"><div class="col-12 col-lg-3"><select name="lines[0][product_variant_id]" class="form-select" required>@foreach ($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][inventory_batch_id]" class="form-select"><option value="">Tanpa batch</option>@foreach ($batches as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][warehouse_location_id]" class="form-select"><option value="">Tanpa rak/bin</option>@foreach ($locations->where('is_active', true) as $location)<option value="{{ $location->id }}">{{ $location->warehouse?->name }} · {{ $location->code }}</option>@endforeach</select></div><div class="col-6 col-lg-2"><select name="lines[0][serial_number_id]" class="form-select"><option value="">Tanpa serial</option>@foreach ($serials as $serial)<option value="{{ $serial->id }}">{{ $serial->serial_number }}</option>@endforeach</select></div><div class="col-6 col-lg-3"><input name="lines[0][quantity_change]" type="number" step="0.001" class="form-control" placeholder="Perubahan (+/-)" required></div></div></div><div class="form-text">Serial hanya untuk pengurangan satu unit; batch dan rak/bin harus sesuai gudang adjustment.</div><button type="button" id="add-adjustment-line" class="btn btn-sm btn-outline-secondary mt-2">+ Tambah baris</button></div>
                    <div class="col-12"><label class="form-label">Catatan wajib</label><textarea name="notes" class="form-control" rows="2" required></textarea></div>
                    <div class="col-12"><button class="btn btn-primary w-100 w-md-auto">Buat draft</button></div>
                </form>
                <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>ID</th><th>Gudang</th><th>Alasan</th><th>Status</th><th></th></tr></thead><tbody>@forelse ($adjustments as $adjustment)<tr><td>#{{ $adjustment->id }}</td><td>{{ $adjustment->warehouse?->name }}</td><td>{{ str_replace('_', ' ', $adjustment->reason) }}</td><td><span class="badge">{{ $adjustment->status }}</span></td><td>@if (in_array($adjustment->status, ['draft', 'reviewed', 'approved'], true))<form method="POST" action="{{ route('inventory.adjustments.action', [$adjustment, $adjustment->status === 'draft' ? 'submit' : ($adjustment->status === 'reviewed' ? 'approve' : 'post')]) }}">@csrf<button class="btn btn-sm btn-outline-primary">{{ $adjustment->status === 'draft' ? 'Kirim review' : ($adjustment->status === 'reviewed' ? 'Setujui' : 'Posting') }}</button></form>@endif</td></tr>@empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada penyesuaian.</td></tr>@endforelse</tbody></table></div>
            </div></div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card h-100"><div class="card-header"><h2 class="card-title">Cycle count</h2></div><div class="card-body">
                <form method="POST" action="{{ route('inventory.counts.store') }}" class="row g-3 mb-4">@csrf
                    <div class="col-12 col-md-6"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-4"><label class="form-label">Referensi</label><input name="reference" class="form-control" placeholder="COUNT-2026-001"></div>
                    <div class="col-12 col-md-4"><label class="form-label">Rak/bin (opsional)</label><select name="warehouse_location_id" class="form-select"><option value="">Seluruh gudang</option>@foreach ($locations->where('is_active', true) as $location)<option value="{{ $location->id }}">{{ $location->warehouse?->name }} · {{ $location->code }}</option>@endforeach</select></div>
                    <div class="col-12"><button class="btn btn-primary w-100 w-md-auto">Ambil snapshot</button></div>
                </form>
                <div class="vstack gap-3">@forelse ($counts as $count)<div class="border rounded p-3"><div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><div><strong>#{{ $count->id }} · {{ $count->warehouse?->name }}</strong><div class="text-secondary small">{{ $count->reference ?: 'Tanpa referensi' }}</div></div><span class="badge align-self-start">{{ $count->status }}</span></div>
                    @if ($count->status === 'counting')<form method="POST" action="{{ route('inventory.counts.record', $count) }}">@csrf<div class="row g-2">@forelse ($count->lines as $line)<div class="col-12 col-md-6"><label class="form-label small">{{ $line->variant?->sku }}@if($line->inventoryBatch) · batch {{ $line->inventoryBatch->batch_number }}@endif@if($line->serialNumber) · serial {{ $line->serialNumber->serial_number }}@endif · ekspektasi {{ number_format((float) $line->expected_quantity, 3) }}</label><input name="quantities[{{ $line->id }}]" type="number" min="0" max="{{ $line->serial_number_id ? 1 : '' }}" step="{{ $line->serial_number_id ? 1 : '0.001' }}" class="form-control" required></div>@empty<div class="col-12 text-secondary small">Snapshot kosong; belum ada movement pada gudang ini.</div>@endforelse</div>@if ($count->lines->isNotEmpty())<button class="btn btn-sm btn-outline-primary mt-3">Kirim hasil hitung</button>@endif</form>
                    @elseif (in_array($count->status, ['reviewed', 'approved'], true))<form method="POST" action="{{ route('inventory.counts.action', [$count, $count->status === 'reviewed' ? 'approve' : 'post']) }}">@csrf<button class="btn btn-sm btn-outline-primary">{{ $count->status === 'reviewed' ? 'Setujui variance' : 'Posting adjustment' }}</button></form>@endif
                </div>@empty<div class="text-center text-secondary py-4">Belum ada stock count.</div>@endforelse</div>
            </div></div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const container = document.getElementById('transfer-lines');
        const add = document.getElementById('add-transfer-line');
        if (!container || !add) return;

        let index = 1;
        add.addEventListener('click', () => {
            const row = container.firstElementChild.cloneNode(true);
            const selects = row.querySelectorAll('select');
            selects[0].name = `lines[${index}][product_variant_id]`;
            selects[1].name = `lines[${index}][source_warehouse_location_id]`;
            selects[1].value = '';
            selects[2].name = `lines[${index}][destination_warehouse_location_id]`;
            selects[2].value = '';
            selects[3].name = `lines[${index}][inventory_batch_id]`;
            selects[3].value = '';
            selects[4].name = `lines[${index}][serial_number_ids][]`;
            Array.from(selects[4].options).forEach(option => option.selected = false);
            const quantity = row.querySelector('input');
            quantity.name = `lines[${index}][quantity]`;
            quantity.value = '';
            container.appendChild(row);
            index += 1;
        });
    })();
    (() => {
        const container = document.getElementById('adjustment-lines');
        const add = document.getElementById('add-adjustment-line');
        if (!container || !add) return;
        let index = 1;
        add.addEventListener('click', () => {
            const row = container.firstElementChild.cloneNode(true);
            const selects = row.querySelectorAll('select');
            selects[0].name = `lines[${index}][product_variant_id]`;
            selects[1].name = `lines[${index}][inventory_batch_id]`;
            selects[1].value = '';
            selects[2].name = `lines[${index}][warehouse_location_id]`;
            selects[2].value = '';
            selects[3].name = `lines[${index}][serial_number_id]`;
            selects[3].value = '';
            const quantity = row.querySelector('input');
            quantity.name = `lines[${index}][quantity_change]`;
            quantity.value = '';
            container.appendChild(row);
            index += 1;
        });
    })();
</script>
@endpush
