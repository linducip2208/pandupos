<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Models\SalesQuotation;
use App\Services\AccountingService;
use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * CRM: leads with status machine, qualification → customer conversion
 * (idempotent), opportunity pipeline with guarded stage transitions,
 * activities with overdue follow-ups, and quotation linkage.
 */
final class CrmService
{
    public function __construct(private AuditService $audit) {}

    public function createLead(int $tenantId, array $data, ?int $actorId = null): CrmLead
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Lead name is required.');
        if (! empty($data['email'])) {
            abort_unless(filter_var($data['email'], FILTER_VALIDATE_EMAIL), 422, 'Lead email is invalid.');
        }

        $lead = CrmLead::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'source' => $data['source'] ?? null, 'status' => CrmLead::NEW,
            'owner_id' => $data['owner_id'] ?? $actorId, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'crm.lead.created', CrmLead::class, $lead->id, null, $lead->toArray());

        return $lead;
    }

    public function transitionLead(CrmLead $lead, string $to, ?int $actorId = null): CrmLead
    {
        $allowed = [
            CrmLead::NEW => [CrmLead::CONTACTED, CrmLead::LOST],
            CrmLead::CONTACTED => [CrmLead::QUALIFIED, CrmLead::LOST],
            CrmLead::QUALIFIED => [CrmLead::CONVERTED, CrmLead::LOST],
            CrmLead::CONVERTED => [],
            CrmLead::LOST => [CrmLead::NEW],
        ];
        abort_unless(in_array($lead->status, CrmLead::STATUSES, true) && in_array($to, CrmLead::STATUSES, true), 422, 'Invalid lead status.');
        abort_unless(in_array($to, $allowed[$lead->status] ?? [], true), 422, "Lead cannot move from [{$lead->status}] to [{$to}].");
        $before = $lead->toArray();
        $lead->update(['status' => $to]);
        $this->audit->log($lead->tenant_id, $actorId, 'crm.lead.transitioned', CrmLead::class, $lead->id, $before, $lead->fresh()->toArray());

        return $lead->fresh();
    }

    /** Qualify → convert: creates the customer Contact exactly once. */
    public function convertLead(CrmLead $lead, ?int $actorId = null): Contact
    {
        return DB::transaction(function () use ($lead, $actorId) {
            $locked = CrmLead::withoutGlobalScopes()->lockForUpdate()->findOrFail($lead->id);
            // Idempotency first: an already-converted lead returns its customer
            // without re-running the status machine.
            if ($locked->converted_contact_id) {
                return Contact::withoutGlobalScopes()->findOrFail($locked->converted_contact_id);
            }
            abort_unless(in_array($locked->status, [CrmLead::CONTACTED, CrmLead::QUALIFIED], true), 422, 'Only contacted or qualified leads can be converted.');
            $contact = Contact::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'type' => 'customer',
                'name' => $locked->name, 'company' => $locked->company,
                'email' => $locked->email, 'phone' => $locked->phone,
            ]);
            $before = $locked->toArray();
            $locked->update(['status' => CrmLead::CONVERTED, 'converted_contact_id' => $contact->id]);
            $this->audit->log($locked->tenant_id, $actorId, 'crm.lead.converted', CrmLead::class, $locked->id, $before, $locked->fresh()->toArray());

            return $contact;
        });
    }

    public function createOpportunity(int $tenantId, array $data, ?int $actorId = null): CrmOpportunity
    {
        $title = trim((string) ($data['title'] ?? ''));
        abort_if($title === '', 422, 'Opportunity title is required.');
        $leadId = isset($data['lead_id']) ? (int) $data['lead_id'] : null;
        if ($leadId) {
            CrmLead::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($leadId);
        }
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        if ($contactId) {
            abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($contactId)->exists(), 422, 'Contact does not belong to tenant.');
        }
        $value = round((float) ($data['value'] ?? 0), 2);
        abort_if($value < 0, 422, 'Opportunity value cannot be negative.');

        $opp = CrmOpportunity::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'lead_id' => $leadId, 'contact_id' => $contactId,
            'title' => $title, 'value' => $value, 'stage' => CrmOpportunity::PROSPECT,
            'expected_close' => $data['expected_close'] ?? null,
            'owner_id' => $data['owner_id'] ?? $actorId, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'crm.opportunity.created', CrmOpportunity::class, $opp->id, null, $opp->toArray());

        return $opp;
    }

    public function advanceOpportunity(CrmOpportunity $opp, string $to, ?int $actorId = null): CrmOpportunity
    {
        $allowed = [
            CrmOpportunity::PROSPECT => [CrmOpportunity::NEGOTIATION, CrmOpportunity::LOST],
            CrmOpportunity::NEGOTIATION => [CrmOpportunity::WON, CrmOpportunity::LOST],
            CrmOpportunity::WON => [],
            CrmOpportunity::LOST => [CrmOpportunity::PROSPECT],
        ];
        abort_unless(in_array($to, CrmOpportunity::STAGES, true), 422, 'Invalid opportunity stage.');
        abort_unless(in_array($to, $allowed[$opp->stage] ?? [], true), 422, "Opportunity cannot move from [{$opp->stage}] to [{$to}].");
        if ($to === CrmOpportunity::WON) {
            abort_if($opp->contact_id === null && $opp->lead?->converted_contact_id === null, 422, 'Winning requires a linked customer: convert the lead or attach a contact first.');
        }
        $before = $opp->toArray();
        $opp->update(['stage' => $to]);
        $this->audit->log($opp->tenant_id, $actorId, 'crm.opportunity.advanced', CrmOpportunity::class, $opp->id, $before, $opp->fresh()->toArray());
        if ($to === CrmOpportunity::WON) {
            $this->postWonToAccounting($opp->fresh(), $actorId);
        }

        return $opp->fresh();
    }

    public function linkQuotation(CrmOpportunity $opp, int $quotationId, ?int $actorId = null): CrmOpportunity
    {
        $quotation = SalesQuotation::withoutGlobalScopes()->where('tenant_id', $opp->tenant_id)->findOrFail($quotationId);
        $before = $opp->toArray();
        $opp->update(['quotation_id' => $quotation->id]);
        if ($opp->contact_id === null && $quotation->contact_id) {
            $opp->update(['contact_id' => $quotation->contact_id]);
        }
        $this->audit->log($opp->tenant_id, $actorId, 'crm.opportunity.quotation_linked', CrmOpportunity::class, $opp->id, $before, $opp->fresh()->toArray());

        return $opp->fresh();
    }

    /**
     * Record a won opportunity as a committed revenue estimate. Idempotent
     * via the opportunity id as source reference; posts Dr Piutang / Cr
     * Pendapatan when accounting is enabled so the pipeline feeds the ledger.
     */
    public function postWonToAccounting(CrmOpportunity $opp, ?int $actorId = null): ?\App\Models\JournalEntry
    {
        if (! app(ModuleRegistry::class)->isEnabled($opp->tenant_id, 'accounting')) {
            return null;
        }
        $accounting = app(AccountingService::class);
        $accounting->ensureDefaultChart($opp->tenant_id);
        $value = round((float) $opp->value, 2);
        if ($value <= 0) {
            return null;
        }
        $existing = \App\Models\JournalEntry::withoutGlobalScopes()->where('tenant_id', $opp->tenant_id)
            ->where('source_type', CrmOpportunity::class)->where('source_id', $opp->id)
            ->where('status', \App\Models\JournalEntry::POSTED)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($accounting, $opp, $actorId, $value) {
            $entry = $accounting->createDraft(
                $opp->tenant_id, now()->toDateString(), 'Peluang menang '.$opp->title,
                [
                    ['account_code' => '1300', 'debit' => $value, 'credit' => 0],
                    ['account_code' => '4100', 'debit' => 0, 'credit' => $value],
                ], CrmOpportunity::class, $opp->id, $actorId
            );

            return $accounting->post($entry, $actorId);
        });
    }

    public function logActivity(int $tenantId, array $data, ?int $actorId = null): CrmActivity
    {
        abort_unless(in_array($data['type'] ?? null, CrmActivity::TYPES, true), 422, 'Invalid activity type.');
        $subject = trim((string) ($data['subject'] ?? ''));
        abort_if($subject === '', 422, 'Activity subject is required.');
        $leadId = isset($data['lead_id']) ? (int) $data['lead_id'] : null;
        $oppId = isset($data['opportunity_id']) ? (int) $data['opportunity_id'] : null;
        abort_if($leadId === null && $oppId === null, 422, 'Activity must attach to a lead or an opportunity.');
        if ($leadId) {
            CrmLead::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($leadId);
        }
        if ($oppId) {
            CrmOpportunity::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($oppId);
        }

        $activity = CrmActivity::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'lead_id' => $leadId, 'opportunity_id' => $oppId,
            'type' => $data['type'], 'subject' => $subject, 'notes' => $data['notes'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null, 'owner_id' => $data['owner_id'] ?? $actorId,
        ]);
        $this->audit->log($tenantId, $actorId, 'crm.activity.logged', CrmActivity::class, $activity->id, null, $activity->toArray());

        return $activity;
    }

    public function completeActivity(CrmActivity $activity, ?int $actorId = null): CrmActivity
    {
        abort_if($activity->done_at !== null, 422, 'Activity is already completed.');
        $before = $activity->toArray();
        $activity->update(['done_at' => now()]);
        $this->audit->log($activity->tenant_id, $actorId, 'crm.activity.completed', CrmActivity::class, $activity->id, $before, $activity->fresh()->toArray());

        return $activity->fresh();
    }

    /** @return array<int, array{stage:string,count:int,value:float}> */
    public function pipeline(int $tenantId): array
    {
        $rows = CrmOpportunity::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->groupBy('stage')->selectRaw('stage, COUNT(*) AS c, SUM(value) AS v')->get();
        $out = [];
        foreach (CrmOpportunity::STAGES as $stage) {
            $row = $rows->firstWhere('stage', $stage);
            $out[] = ['stage' => $stage, 'count' => (int) ($row?->c ?? 0), 'value' => round((float) ($row?->v ?? 0), 2)];
        }

        return $out;
    }

    /** @return array<int, CrmActivity> */
    public function overdueFollowUps(int $tenantId, ?int $ownerId = null): array
    {
        return CrmActivity::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNull('done_at')->where('scheduled_at', '<', now())
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->orderBy('scheduled_at')->limit(100)->get()->all();
    }
}
