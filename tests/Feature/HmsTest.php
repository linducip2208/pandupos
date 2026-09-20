<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HmsAppointment;
use App\Models\HmsInvoice;
use App\Models\HmsPatient;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\HmsService;
use App\Services\ModuleManager;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * HMS module: patients, doctors, conflict-guarded appointments, records,
 * invoices with pharmacy stock discipline, isolation, RBAC and API.
 */
class HmsTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko HMS', bool $enableHms = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableHms) {
            app(ModuleManager::class)->enable($tenant->id, 'hms');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    /** @return array{Warehouse, variant} */
    private function stocked(string $sku, $tenant, int $qty = 10): array
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Apotek '.$sku, 'code' => 'WH-'.uniqid()]);
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => $sku, 'sku' => $sku, 'product_type' => 'stock', 'track_inventory' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default', 'sku' => $sku.'-V', 'purchase_price' => 5000, 'sell_price' => 8000]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, $qty, 5000, 'opening', null);

        return [$warehouse, $variant];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko HMS Gate', false);

        $this->actingAs($owner)->get('/hms')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/hms/patients', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'hms');
        $this->actingAs($owner)->get('/hms')->assertOk()->assertSeeText('Jadwal kunjungan');
    }

    public function test_appointment_conflicts_and_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(HmsService::class);
        $patient = $svc->registerPatient($tenant->id, ['name' => 'Pasien A'], $owner->id);
        $this->assertSame('PASIEN-0001', $patient->code);
        $doctor = $svc->registerDoctor($tenant->id, ['name' => 'Dr. S', 'consultation_fee' => 150000], $owner->id);
        $slot = now()->addDay()->setHour(9)->setMinute(0)->toDateTimeString();

        $a1 = $svc->schedule($tenant->id, $patient->id, $doctor->id, $slot, 30, $owner->id);
        // Overlapping slot for the same doctor is refused ...
        try {
            $svc->schedule($tenant->id, $patient->id, $doctor->id, date('Y-m-d H:i:s', strtotime($slot.' +15 minutes')), 30, $owner->id);
            $this->fail('Overlapping appointment must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        // ... but a back-to-back slot is fine, and so is another doctor.
        $a2 = $svc->schedule($tenant->id, $patient->id, $doctor->id, date('Y-m-d H:i:s', strtotime($slot.' +30 minutes')), 30, $owner->id);
        $doctor2 = $svc->registerDoctor($tenant->id, ['name' => 'Dr. T'], $owner->id);
        $svc->schedule($tenant->id, $patient->id, $doctor2->id, $slot, 30, $owner->id);

        // Records require check-in; one record per visit.
        try {
            $svc->createRecord($tenant->id, $a1->id, ['diagnosis' => 'Flu'], $owner->id);
            $this->fail('Record before check-in must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->transitionAppointment($a1, 'checked_in', $owner->id);
        $svc->createRecord($tenant->id, $a1->id, ['diagnosis' => 'Flu', 'prescription' => 'Paracetamol'], $owner->id);
        try {
            $svc->createRecord($tenant->id, $a1->id, ['diagnosis' => 'Lagi'], $owner->id);
            $this->fail('Duplicate record must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('completed', $svc->transitionAppointment($a1->refresh(), 'completed', $owner->id)->status);
        $this->assertSame(3, HmsAppointment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_invoice_pharmacy_dispenses_once_at_paid(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $variant] = $this->stocked('OBAT', $tenant);
        $svc = app(HmsService::class);
        $patient = $svc->registerPatient($tenant->id, ['name' => 'Pasien B'], $owner->id);

        $invoice = $svc->createInvoice($tenant->id, $patient->id, [
            'consultation_fee' => 150000,
            'pharmacy' => [['variant_id' => $variant->id, 'quantity' => 2]],
        ], $owner->id);
        $this->assertSame(166000.0, (float) $invoice->total); // 150000 + 2×8000
        $this->assertSame(10.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        // Partial payment leaves stock untouched and balance open.
        $svc->payInvoice($invoice, 100000, $owner->id);
        $mid = $invoice->refresh();
        $this->assertSame('partial', $mid->status);
        $this->assertSame(10.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));

        $svc->payInvoice($mid, 66000, $owner->id);
        $done = $invoice->refresh();
        $this->assertSame('paid', $done->status);
        $this->assertSame(8.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference_type', 'hms_dispense')->where('reference_id', $done->id)->count());

        // Overpayment refused.
        $invoice2 = $svc->createInvoice($tenant->id, $patient->id, ['consultation_fee' => 50000], $owner->id);
        try {
            $svc->payInvoice($invoice2, 60000, $owner->id);
            $this->fail('Overpayment must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko HMS A');
        [$tenantB, $ownerB] = $this->context('Toko HMS B');
        $patientA = app(HmsService::class)->registerPatient($tenantA->id, ['name' => 'Rahasia A'], null);

        TenantContext::setId($tenantB->id);
        $this->assertSame(0, HmsPatient::query()->count());
        $this->actingAs($ownerB)->get('/hms')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->get("/api/v1/hms/patients/{$patientA->id}/history", ['X-Tenant-ID' => $tenantB->id])->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/hms')->assertForbidden();
        $member->givePermissionTo('hms.view');
        $this->actingAs($member)->get('/hms')->assertOk();
        $this->actingAs($member)->post('/hms/patients', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];
        $svc = app(HmsService::class);

        $patient = $this->actingAs($owner)->postJson('/api/v1/hms/patients', ['name' => 'API Pasien'], $headers)->assertCreated();
        $patientId = $patient->json('data.id');
        $doctor = $svc->registerDoctor($tenant->id, ['name' => 'API Dr'], $owner->id);
        $slot = now()->addDays(2)->setHour(10)->setMinute(0)->toDateTimeString();

        $appt = $this->actingAs($owner)->postJson('/api/v1/hms/appointments', [
            'patient_id' => $patientId, 'doctor_id' => $doctor->id, 'scheduled_at' => $slot,
        ], $headers)->assertCreated();
        $apptId = $appt->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/hms/appointments/{$apptId}/transition", ['to' => 'checked_in'], $headers)->assertOk();

        $inv = $this->actingAs($owner)->postJson('/api/v1/hms/invoices', [
            'patient_id' => $patientId, 'appointment_id' => $apptId, 'consultation_fee' => 100000,
        ], $headers)->assertCreated();
        $invId = $inv->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/hms/invoices/{$invId}/pay", ['amount' => 100000], $headers)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->actingAs($owner)->getJson("/api/v1/hms/patients/{$patientId}/history", $headers)->assertOk()->assertJsonPath('data.appointments.0.id', $apptId);
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();
        [$warehouse, $variant] = $this->stocked('WEB', $tenant);
        $doctor = app(HmsService::class)->registerDoctor($tenant->id, ['name' => 'Web Dr', 'consultation_fee' => 50000], $owner->id);

        $this->actingAs($owner)->post('/hms/patients', ['name' => 'Web Pasien'])->assertRedirect();
        $patient = HmsPatient::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $slot = now()->addDays(3)->setHour(11)->setMinute(0)->format('Y-m-d\TH:i');
        $this->actingAs($owner)->post('/hms/appointments', ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => $slot])->assertRedirect();
        $appt = HmsAppointment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post("/hms/appointments/{$appt->id}/transition", ['to' => 'checked_in'])->assertRedirect();
        $this->actingAs($owner)->post("/hms/appointments/{$appt->id}/record", ['diagnosis' => 'Sehat'])->assertRedirect();
        $this->actingAs($owner)->post('/hms/invoices', [
            'patient_id' => $patient->id, 'appointment_id' => $appt->id, 'consultation_fee' => 50000,
            'pharmacy' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertRedirect();
        $invoice = HmsInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(58000.0, (float) $invoice->total);
        $this->actingAs($owner)->post("/hms/invoices/{$invoice->id}/pay", ['amount' => 58000])->assertRedirect();
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(9.0, app(StockService::class)->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->actingAs($owner)->get('/hms')->assertOk()->assertSeeText('Web Pasien');
    }
}
