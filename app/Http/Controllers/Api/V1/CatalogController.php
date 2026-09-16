<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\UnitConversionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function categories(Request $request)
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => Category::orderBy('name')->paginate(20)]);
    }

    public function storeCategory(Request $request)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate(['name' => 'required|string|max:255', 'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)]]);
        $row = Category::create($data);

        return response()->json(['data' => $row], 201);
    }

    public function brands()
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => Brand::orderBy('name')->paginate(20)]);
    }

    public function storeBrand(Request $request)
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => 'required|string|max:255']);
        $row = Brand::create($data);

        return response()->json(['data' => $row], 201);
    }

    public function units()
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => Unit::orderBy('name')->paginate(20)]);
    }

    public function storeUnit(Request $request)
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => 'required|string|max:255', 'short_name' => 'required|string|max:16']);
        $row = Unit::create($data);

        return response()->json(['data' => $row], 201);
    }

    public function unitConversions()
    {
        $this->authorize('viewAny', Product::class);

        return response()->json([
            'data' => UnitConversion::with(['fromUnit', 'toUnit'])->orderBy('from_unit_id')->paginate(20),
        ]);
    }

    public function storeUnitConversion(Request $request, UnitConversionService $conversions)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'from_unit_id' => ['required', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'to_unit_id' => ['required', 'different:from_unit_id', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'factor' => 'required|numeric|gt:0',
        ]);

        $row = $conversions->define(
            $tenantId,
            (int) $data['from_unit_id'],
            (int) $data['to_unit_id'],
            $data['factor']
        );

        return response()->json(['data' => $row->load(['fromUnit', 'toUnit'])], 201);
    }
}
