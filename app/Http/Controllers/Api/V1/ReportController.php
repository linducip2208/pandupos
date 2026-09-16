<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function sales(Request $request, ReportService $reports)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $tenantId = \App\Support\TenantContext::id();

        return response()->json([
            'summary' => $reports->salesSummary($tenantId, $request->from, $request->to),
            'by_product' => $reports->salesByProduct($tenantId, $request->from, $request->to),
            'purchases' => $reports->purchaseSummary($tenantId, $request->from, $request->to),
        ]);
    }

    public function stock(ReportService $reports)
    {
        return response()->json([
            'on_hand' => $reports->stockOnHand(\App\Support\TenantContext::id()),
        ]);
    }
}
