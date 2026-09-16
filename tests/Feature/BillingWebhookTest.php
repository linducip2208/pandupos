<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BillingService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_idempotent(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Bill', $owner);
        $svc = app(BillingService::class);

        $inv = $svc->createInvoice($tenant->id, null, 100000);
        $svc->recordAttempt($tenant->id, $inv->id, 'midtrans', 'ref-123', 100000);

        $t1 = $svc->handleWebhook('midtrans', 'ref-123', ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 100000], 'success');
        $t2 = $svc->handleWebhook('midtrans', 'ref-123', ['tenant_id' => $tenant->id, 'invoice_id' => $inv->id, 'amount' => 100000], 'success');

        $this->assertEquals($t1->id, $t2->id);
        $this->assertEquals('paid', $inv->refresh()->status);
    }
}
