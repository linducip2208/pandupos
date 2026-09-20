<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AssetService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Asset::class);

        return response()->json(['data' => Asset::query()->orderByDesc('id')->limit(200)->get()]);
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

        return response()->json(['data' => $assets->register(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function schedule(AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('viewAny', Asset::class);

        return response()->json(['data' => ['asset' => $model, 'book_value' => $assets->bookValue($model), 'schedule' => $assets->schedule($model)]]);
    }

    public function dispose(Request $request, AssetService $assets, int $asset)
    {
        $model = Asset::query()->findOrFail($asset);
        $this->authorize('manage', $model);
        $data = $request->validate(['proceeds' => 'required|numeric|min:0', 'as_of' => 'nullable|date']);

        return response()->json(['data' => $assets->dispose($model, (float) $data['proceeds'], $data['as_of'] ?? now()->toDateString(), $request->user()->id)]);
    }
}
