<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingTransaction;
use App\Models\PaymentWebhook;
use App\Models\User;
use App\Services\BillingService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookSafetyTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    private string $secret = 'wh-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payment_sdk.sandbox.secret' => $this->secret]);
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko WH', $owner);
        $invoice = app(BillingService::class)->createInvoice($tenant->id, null, 100000, 0, [], 10000);
        $this->payload = [
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'gateway_ref' => 'external-ref-1',
            'amount' => 110000,
            'status' => 'success',
        ];
    }

    private function postSigned(array|string $payload, ?string $secret = null, string $gateway = 'sandbox', string $headerName = 'X-Webhook-Signature'): TestResponse
    {
        $secret ??= $this->secret;
        $body = is_string($payload) ? $payload : json_encode($payload);

        return $this->call('POST', "/api/v1/payments/webhooks/{$gateway}", [], [], [], [
            'HTTP_'.$headerName => hash_hmac('sha256', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_valid_signed_webhook_pays_invoice_and_is_idempotent(): void
    {
        $this->postSigned($this->payload)->assertOk()->assertJsonPath('data.status', 'success');
        $this->assertSame(1, PaymentWebhook::where('gateway_ref', 'external-ref-1')->count());
        $this->assertSame('success', BillingTransaction::where('gateway_ref', 'external-ref-1')->first()->status);
        $this->assertSame('paid', BillingInvoice::withoutGlobalScopes()->find($this->payload['invoice_id'])->status);

        // Replay the exact same event: same ref returns existing state, never double-applies.
        $this->postSigned($this->payload)->assertOk();
        $this->assertSame(1, PaymentWebhook::where('gateway_ref', 'external-ref-1')->count());
        $this->assertSame(1, BillingTransaction::where('gateway_ref', 'external-ref-1')->count());
    }

    public function test_replayed_success_cannot_be_downgraded_by_later_failed_status(): void
    {
        $this->postSigned($this->payload)->assertOk();

        $failed = $this->payload;
        $failed['status'] = 'failed';
        $this->postSigned($failed)->assertOk();
        // A settled transaction is immutable: no downgrade, invoice stays paid.
        $this->assertSame('success', BillingTransaction::where('gateway_ref', 'external-ref-1')->first()->status);
        $this->assertSame('paid', BillingInvoice::withoutGlobalScopes()->find($this->payload['invoice_id'])->status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postSigned($this->payload, 'wrong-secret')->assertUnauthorized();
        $this->assertSame(0, PaymentWebhook::count());
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->postJson('/api/v1/payments/webhooks/sandbox', $this->payload)->assertUnauthorized();
    }

    public function test_unknown_gateway_is_404(): void
    {
        $this->postSigned($this->payload, gateway: 'nonexistent')->assertNotFound();
    }

    public function test_malformed_or_ref_less_payload_is_rejected(): void
    {
        $this->postSigned([])->assertUnprocessable();
        $this->postSigned(['foo' => 'bar'])->assertUnprocessable();
        $this->postSigned('<xml>not-json</xml>')->assertUnprocessable();
        $bad = $this->payload;
        unset($bad['gateway_ref']);
        $this->postSigned($bad)->assertUnprocessable();
    }
}
