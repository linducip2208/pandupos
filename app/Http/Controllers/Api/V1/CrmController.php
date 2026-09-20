<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Services\CrmService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    public function leads()
    {
        $this->authorize('viewAny', CrmLead::class);

        return response()->json(['data' => CrmLead::query()->orderByDesc('id')->limit(100)->get()]);
    }

    public function storeLead(Request $request, CrmService $crm)
    {
        $this->authorize('create', CrmLead::class);
        $data = $request->validate([
            'name' => 'required|string|max:128', 'company' => 'nullable|string|max:128',
            'email' => 'nullable|email|max:128', 'phone' => 'nullable|string|max:64',
            'source' => 'nullable|string|max:64', 'notes' => 'nullable|string',
        ]);

        return response()->json(['data' => $crm->createLead(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function opportunities(CrmService $crm)
    {
        $this->authorize('viewAny', CrmLead::class);

        return response()->json([
            'data' => CrmOpportunity::query()->orderByDesc('id')->limit(100)->get(),
            'pipeline' => $crm->pipeline(TenantContext::idOrFail()),
        ]);
    }

    public function storeOpportunity(Request $request, CrmService $crm)
    {
        $this->authorize('create', CrmLead::class);
        $data = $request->validate([
            'title' => 'required|string|max:160', 'lead_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer', 'value' => 'required|numeric|min:0',
            'expected_close' => 'nullable|date', 'notes' => 'nullable|string',
        ]);

        return response()->json(['data' => $crm->createOpportunity(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function advanceOpportunity(Request $request, CrmService $crm, int $opportunity)
    {
        $model = CrmOpportunity::query()->findOrFail($opportunity);
        $this->authorize('manageOpportunity', $model);
        $data = $request->validate(['to' => 'required|string']);

        return response()->json(['data' => $crm->advanceOpportunity($model, $data['to'], $request->user()->id)]);
    }
}
