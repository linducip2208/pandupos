<?php

namespace App\Http\Controllers;

use App\Models\BarcodeProfile;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Services\BarcodeParserService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);
        $parsed = null;
        if ($request->filled('barcode')) {
            $parsed = app(BarcodeParserService::class)->parse(TenantContext::idOrFail(), $request->string('barcode')->toString());
        }

        return view('barcodes.index', [
            'profiles' => BarcodeProfile::query()->latest()->get(),
            'variants' => ProductVariant::query()->with('product')->where('is_active', true)->orderBy('sku')->get(),
            'parsed' => $parsed,
        ]);
    }

    public function storeProfile(Request $request, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $this->profileData($request, $tenantId);
        $profile = BarcodeProfile::create($data);
        $audit->log($tenantId, $request->user()->id, 'barcode.profile.created', BarcodeProfile::class, $profile->id, null, $profile->toArray());

        return back()->with('status', 'Profil barcode timbangan dibuat.');
    }

    public function updateProfile(Request $request, BarcodeProfile $profile, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        abort_unless($profile->tenant_id === TenantContext::idOrFail(), 404);
        $before = $profile->toArray();
        $profile->update($this->profileData($request, $profile->tenant_id, $profile));
        $audit->log($profile->tenant_id, $request->user()->id, 'barcode.profile.updated', BarcodeProfile::class, $profile->id, $before, $profile->fresh()->toArray());

        return back()->with('status', 'Profil barcode timbangan diperbarui.');
    }

    public function archiveProfile(Request $request, BarcodeProfile $profile, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        abort_unless($profile->tenant_id === TenantContext::idOrFail(), 404);
        $before = $profile->toArray();
        $profile->update(['is_active' => false]);
        $audit->log($profile->tenant_id, $request->user()->id, 'barcode.profile.archived', BarcodeProfile::class, $profile->id, $before, $profile->fresh()->toArray());

        return back()->with('status', 'Profil barcode dinonaktifkan.');
    }

    public function labels(Request $request): View
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate([
            'variants' => ['required', 'array', 'min:1'], 'variants.*' => ['integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:200'], 'template' => ['required', Rule::in(['a4', 'thermal'])],
        ]);
        $variants = ProductVariant::query()->with('product')->whereIn('id', $data['variants'])->whereNotNull('barcode')->get();
        abort_if($variants->count() !== count(array_unique($data['variants'])), 422, 'Semua varian harus milik tenant dan memiliki barcode.');
        $generator = new BarcodeGeneratorSVG;
        $labels = $variants->flatMap(fn ($variant) => collect(range(1, $data['quantity']))->map(fn () => [
            'variant' => $variant,
            'svg' => $generator->getBarcode($variant->barcode, $generator::TYPE_CODE_128, 2, 54),
        ]));

        return view('barcodes.labels', ['labels' => $labels, 'template' => $data['template']]);
    }

    private function profileData(Request $request, int $tenantId, ?BarcodeProfile $profile = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['required', 'string', 'max:16', Rule::unique('barcode_profiles')->where('tenant_id', $tenantId)->ignore($profile)],
            'total_length' => ['required', 'integer', 'min:6', 'max:32'],
            'item_start' => ['required', 'integer', 'min:0', 'max:31'], 'item_length' => ['required', 'integer', 'min:1', 'max:16'],
            'value_start' => ['required', 'integer', 'min:0', 'max:31'], 'value_length' => ['required', 'integer', 'min:1', 'max:16'],
            'value_type' => ['required', Rule::in(['weight', 'price'])], 'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        abort_if($data['total_length'] < $data['item_start'] + $data['item_length'] || $data['total_length'] < $data['value_start'] + $data['value_length'], 422, 'Posisi field melewati panjang barcode.');
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
