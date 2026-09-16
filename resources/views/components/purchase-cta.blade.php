@php($whatsapp = config('marketing.whatsapp_url'))
@php($sourceCode = config('marketing.source_code_url'))
<div class="purchase-cta" x-data="{ open: false }" x-init="if (!sessionStorage.getItem('pandupos-cta-dismissed')) setTimeout(() => open = true, 25000)">
    @if($whatsapp)<a class="purchase-float" href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" aria-label="Hubungi melalui WhatsApp">WA</a>@endif
    <div class="purchase-popup" x-show="open" x-transition.scale.origin.bottom.right style="display:none">
        <button type="button" @click="open=false;sessionStorage.setItem('pandupos-cta-dismissed','1')" aria-label="Tutup">×</button>
        <span class="eyebrow">SOURCE CODE SIAP DIKEMBANGKAN</span>
        <strong>Bangun produk POS Anda dari fondasi yang sudah berjalan.</strong>
        <p>Lihat dokumentasi teknis, alur demo, tenant isolation, laporan, dan portal pelanggan.</p>
        <div>@if($sourceCode)<a class="button button-primary" href="{{ $sourceCode }}" target="_blank" rel="noopener noreferrer">Lihat Penawaran</a>@endif<a class="button button-secondary" href="{{ route('docs') }}">Dokumentasi</a></div>
    </div>
</div>
