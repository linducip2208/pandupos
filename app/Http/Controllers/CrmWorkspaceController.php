<?php

namespace App\Http\Controllers;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Services\CrmService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class CrmWorkspaceController extends Controller
{
    public function index(CrmService $crm)
    {
        $this->authorize('viewAny', CrmLead::class);
        $tenantId = TenantContext::idOrFail();

        return view('crm.index', [
            'leads' => CrmLead::query()->with('owner')->orderByDesc('id')->limit(100)->get(),
            'opportunities' => CrmOpportunity::query()->with(['lead', 'contact'])->orderByDesc('id')->limit(100)->get(),
            'pipeline' => $crm->pipeline($tenantId),
            'overdue' => $crm->overdueFollowUps($tenantId),
        ]);
    }

    public function storeLead(Request $request, CrmService $crm)
    {
        $this->authorize('create', CrmLead::class);
        $data = $request->validate([
            'name' => 'required|string|max:128', 'company' => 'nullable|string|max:128',
            'email' => 'nullable|email|max:128', 'phone' => 'nullable|string|max:64',
            'source' => 'nullable|string|max:64', 'notes' => 'nullable|string',
        ]);
        $crm->createLead(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Lead '.$data['name'].' dibuat.');
    }

    public function transitionLead(Request $request, CrmService $crm, int $lead)
    {
        $model = CrmLead::query()->findOrFail($lead);
        $this->authorize('manageLead', $model);
        $data = $request->validate(['to' => 'required|string']);
        $crm->transitionLead($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Lead pindah ke status '.$data['to'].'.');
    }

    public function convertLead(Request $request, CrmService $crm, int $lead)
    {
        $model = CrmLead::query()->findOrFail($lead);
        $this->authorize('manageLead', $model);
        $contact = $crm->convertLead($model, $request->user()->id);

        return back()->with('status', 'Lead dikonversi menjadi pelanggan '.$contact->name.'.');
    }

    public function storeOpportunity(Request $request, CrmService $crm)
    {
        $this->authorize('create', CrmLead::class);
        $data = $request->validate([
            'title' => 'required|string|max:160', 'lead_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer', 'value' => 'required|numeric|min:0',
            'expected_close' => 'nullable|date', 'notes' => 'nullable|string',
        ]);
        $crm->createOpportunity(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Opportunity '.$data['title'].' dibuat.');
    }

    public function advanceOpportunity(Request $request, CrmService $crm, int $opportunity)
    {
        $model = CrmOpportunity::query()->findOrFail($opportunity);
        $this->authorize('manageOpportunity', $model);
        $data = $request->validate(['to' => 'required|string']);
        $crm->advanceOpportunity($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Opportunity pindah ke tahap '.$data['to'].'.');
    }

    public function linkQuotation(Request $request, CrmService $crm, int $opportunity)
    {
        $model = CrmOpportunity::query()->findOrFail($opportunity);
        $this->authorize('manageOpportunity', $model);
        $data = $request->validate(['quotation_id' => 'required|integer']);
        $crm->linkQuotation($model, (int) $data['quotation_id'], $request->user()->id);

        return back()->with('status', 'Quotation ditautkan ke opportunity.');
    }

    public function storeActivity(Request $request, CrmService $crm)
    {
        $this->authorize('create', CrmLead::class);
        $data = $request->validate([
            'lead_id' => 'nullable|integer', 'opportunity_id' => 'nullable|integer',
            'type' => 'required|string', 'subject' => 'required|string|max:160',
            'notes' => 'nullable|string', 'scheduled_at' => 'nullable|date',
        ]);
        $crm->logActivity(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Aktivitas dicatat.');
    }

    public function completeActivity(Request $request, CrmService $crm, int $activity)
    {
        $model = CrmActivity::query()->findOrFail($activity);
        $this->authorize('create', CrmLead::class);
        $crm->completeActivity($model, $request->user()->id);

        return back()->with('status', 'Aktivitas selesai.');
    }
}
