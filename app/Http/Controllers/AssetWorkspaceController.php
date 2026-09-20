<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\User;
use App\Services\AssetService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AssetWorkspaceController extends Controller
{
    public function index(AssetService $assets)
    {
        $this->authorize('viewAny', Asset::class);
        $list = Asset::query()->with('custodian')->orderByDesc('id')->limit(200)->get();
        $books = [];
        foreach ($list as $asset) {
            $books[$asset->id] = $assets->bookValue($asset);
        }

        return view('asset.index', ['assets' => $list, 'books' => $books, 'users' => User::query()->orderBy('name')->limit(200)->get()]);
    }

    public function store(Request $request, AssetService $assets)
    {
        $this->authorize('create', Asset::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'code' => 'nullable|string|max:32',
            'category' => 'nullable|string|max:64', 'purchase_date' => 'nullable|date',
            'purchase_cost' => 'required|numeric|gt:0', 'salvage_value' => 'nullable|numeric|min:0',
            'useful_life_months' => 'required|integer|min:1',
            'depreciation_method' => 'nullable|in:straight_line,declining_balance',
            'location' => 'nullable|string|max:128',
        ]);
        $assets->register(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Aset '.$data['name'].' didaftarkan.');
    }

    public function schedule(AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('viewAny', Asset::class);

        return view('asset.schedule', ['asset' => $model, 'rows' => $assets->schedule($model)]);
    }

    public function transfer(Request $request, AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('manage', $model);
        $data = $request->validate(['to_custodian_id' => 'nullable|integer', 'to_location' => 'nullable|string|max:128', 'notes' => 'nullable|string']);
        $assets->transfer($model, isset($data['to_custodian_id']) ? (int) $data['to_custodian_id'] : null, $data['to_location'] ?? null, $request->user()->id, $data['notes'] ?? null);

        return back()->with('status', 'Aset dipindahtangankan.');
    }

    public function maintain(Request $request, AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'kind' => 'nullable|in:preventive,corrective', 'cost' => 'nullable|numeric|min:0',
            'maintained_on' => 'nullable|date', 'next_due_on' => 'nullable|date', 'notes' => 'nullable|string',
        ]);
        $assets->logMaintenance($model, $data, $request->user()->id);

        return back()->with('status', 'Perawatan dicatat.');
    }

    public function returnFromMaintenance(Request $request, AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('manage', $model);
        $assets->returnFromMaintenance($model, $request->user()->id);

        return back()->with('status', 'Aset kembali aktif.');
    }

    public function dispose(Request $request, AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('manage', $model);
        $data = $request->validate(['proceeds' => 'required|numeric|min:0', 'as_of' => 'nullable|date']);
        $done = $assets->dispose($model, (float) $data['proceeds'], $data['as_of'] ?? now()->toDateString(), $request->user()->id);

        return back()->with('status', 'Aset dilepas. Selisih Rp '.number_format($done->disposal_gain_loss, 0, ',', '.').'.');
    }
}
