<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function categories(Request $request)
    {
        return response()->json(['data' => Category::orderBy('name')->paginate(20)]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'parent_id' => 'nullable|exists:categories,id']);
        $row = Category::create($data);

        return response()->json(['data' => $row], 201);
    }

    public function brands()
    {
        return response()->json(['data' => Brand::orderBy('name')->paginate(20)]);
    }

    public function storeBrand(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        $row = Brand::create($data);

        return response()->json(['data' => $row], 201);
    }

    public function units()
    {
        return response()->json(['data' => Unit::orderBy('name')->paginate(20)]);
    }

    public function storeUnit(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'short_name' => 'required|string|max:16']);
        $row = Unit::create($data);

        return response()->json(['data' => $row], 201);
    }
}
