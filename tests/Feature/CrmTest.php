<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\CrmOpportunity;
use App\Models\Membership;
use App\Models\User;
use App\Services\CrmService;
use App\Services\ModuleManager;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * CRM module: lead machine, idempotent conversion, guarded pipeline,
 * quotation linkage, follow-ups, tenant isolation, RBAC and API.
 */
class CrmTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko CRM', bool $enableCrm = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableCrm) {
            app(ModuleManager::class)->enable($tenant->id, 'crm');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko CRM Gate', false);

        $this->actingAs($owner)->get('/crm')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/crm/leads', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'crm');
        $this->actingAs($owner)->get('/crm')->assertOk()->assertSeeText('Prospek');
    }

    public function test_lead_status_machine_rejects_skips(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(CrmService::class);

        $lead = $svc->createLead($tenant->id, ['name' => 'Budi', 'email' => 'budi@example.com'], $owner->id);
        $this->assertSame('new', $lead->status);

        try {
            $svc->transitionLead($lead, 'qualified', $owner->id);
            $this->fail('Skipping contacted must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->transitionLead($lead, 'contacted', $owner->id);
        $svc->transitionLead($lead, 'qualified', $owner->id);
        $this->assertSame('qualified', $lead->refresh()->status);
    }

    public function test_convert_creates_customer_exactly_once(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(CrmService::class);

        $lead = $svc->createLead($tenant->id, ['name' => 'Sari', 'company' => 'PT Sari', 'email' => 'sari@example.com', 'phone' => '0811'], $owner->id);
        $svc->transitionLead($lead, 'contacted', $owner->id);

        $contact = $svc->convertLead($lead, $owner->id);
        $this->assertSame('customer', $contact->type);
        $this->assertSame('Sari', $contact->name);
        $this->assertSame('converted', $lead->refresh()->status);

        // Second conversion returns the same customer: no duplicate contact.
        $again = $svc->convertLead($lead->refresh(), $owner->id);
        $this->assertSame($contact->id, $again->id);
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', 'sari@example.com')->count());
    }

    public function test_pipeline_guards_require_customer_for_won(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(CrmService::class);

        $opp = $svc->createOpportunity($tenant->id, ['title' => 'Deal Kopi', 'value' => 5000000], $owner->id);
        $this->assertSame('prospect', $opp->stage);

        try {
            $svc->advanceOpportunity($opp, 'won', $owner->id);
            $this->fail('Jumping to won must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->advanceOpportunity($opp, 'negotiation', $owner->id);
        // Still no customer: won stays blocked.
        try {
            $svc->advanceOpportunity($opp->refresh(), 'won', $owner->id);
            $this->fail('Won without customer must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $lead = $svc->createLead($tenant->id, ['name' => 'Prospek Deal'], $owner->id);
        $svc->transitionLead($lead, 'contacted', $owner->id);
        $contact = $svc->convertLead($lead, $owner->id);
        $opp->update(['contact_id' => $contact->id, 'lead_id' => $lead->id]);
        $won = $svc->advanceOpportunity($opp->refresh(), 'won', $owner->id);
        $this->assertSame('won', $won->stage);

        $pipeline = $svc->pipeline($tenant->id);
        $this->assertSame(5000000.0, collect($pipeline)->firstWhere('stage', 'won')['value']);
    }

    public function test_activities_track_overdue_follow_ups(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(CrmService::class);

        $lead = $svc->createLead($tenant->id, ['name' => 'Follow'], $owner->id);
        $past = $svc->logActivity($tenant->id, [
            'lead_id' => $lead->id, 'type' => 'follow-up', 'subject' => 'Telepon ulang',
            'scheduled_at' => now()->subDay()->toDateTimeString(),
        ], $owner->id);
        $future = $svc->logActivity($tenant->id, [
            'lead_id' => $lead->id, 'type' => 'call', 'subject' => 'Rencana kunjungan',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ], $owner->id);

        $overdue = $svc->overdueFollowUps($tenant->id);
        $this->assertSame([$past->id], array_map(fn ($a) => $a->id, $overdue));

        $svc->completeActivity($past, $owner->id);
        $this->assertSame([], $svc->overdueFollowUps($tenant->id));
        $this->assertNotNull($future->refresh()->scheduled_at);
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko CRM A');
        [$tenantB, $ownerB] = $this->context('Toko CRM B');
        $svc = app(CrmService::class);
        $leadA = $svc->createLead($tenantA->id, ['name' => 'Rahasia A'], null);

        // Scoped queries never leak A's leads into B.
        TenantContext::setId($tenantB->id);
        $this->assertSame(0, CrmLead::query()->where('id', $leadA->id)->count());
        $this->actingAs($ownerB)->get('/crm')->assertOk()->assertDontSee('Rahasia A');

        // Cross-tenant transition attempts 404 (route-model binding is tenant-scoped).
        $this->actingAs($ownerB)->post("/crm/leads/{$leadA->id}/transition", ['to' => 'lost'])->assertNotFound();

        // Member without CRM permissions is denied.
        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/crm')->assertForbidden();
        $member->givePermissionTo('crm.view');
        $this->actingAs($member)->get('/crm')->assertOk();
        $this->actingAs($member)->post('/crm/leads', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_lead_and_pipeline(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];

        $lead = $this->actingAs($owner)->postJson('/api/v1/crm/leads', ['name' => 'API Lead', 'email' => 'api@example.com'], $headers)
            ->assertCreated()->assertJsonPath('data.status', 'new');
        $leadId = $lead->json('data.id');

        $this->actingAs($owner)->postJson('/api/v1/crm/opportunities', [
            'title' => 'API Deal', 'value' => 750000, 'lead_id' => $leadId,
        ], $headers)->assertCreated()->assertJsonPath('data.stage', 'prospect');

        $this->actingAs($owner)->getJson('/api/v1/crm/opportunities', $headers)->assertOk()
            ->assertJsonPath('pipeline.0.stage', 'prospect')
            ->assertJsonPath('pipeline.0.value', 750000);

        $this->actingAs($owner)->postJson('/api/v1/crm/leads', ['name' => 'Bad', 'email' => 'not-an-email'], $headers)->assertStatus(422);
    }

    public function test_workspace_full_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/crm/leads', ['name' => 'Web Lead'])->assertRedirect();
        $lead = CrmLead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/crm/leads/{$lead->id}/transition", ['to' => 'contacted'])->assertRedirect();
        $this->actingAs($owner)->post("/crm/leads/{$lead->id}/convert")->assertRedirect()->assertSessionHas('status');
        $this->assertSame('converted', $lead->refresh()->status);

        $this->actingAs($owner)->post('/crm/opportunities', ['title' => 'Web Deal', 'value' => 100000])->assertRedirect();
        $opp = CrmOpportunity::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($owner)->post("/crm/opportunities/{$opp->id}/advance", ['to' => 'negotiation'])->assertRedirect();

        $this->actingAs($owner)->post('/crm/activities', [
            'lead_id' => $lead->id, 'type' => 'note', 'subject' => 'Catatan web',
        ])->assertRedirect();

        $this->actingAs($owner)->get('/crm')->assertOk()->assertSeeText('Web Lead')->assertSeeText('Web Deal');
    }
}
