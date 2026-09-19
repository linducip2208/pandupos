<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    /**
     * Inbound payment webhook. Signature-authenticated via gateway secret
     * (services.payment_sdk.<gateway>.secret). Same gateway_ref is idempotent:
     * replays return duplicate without double-applying payment state.
     */
    public function handle(Request $request, string $gateway, BillingService $billing)
    {
        $secret = config("services.payment_sdk.{$gateway}.secret");

        abort_if($secret === null || $secret === '' || $secret === 'optional', 404, 'Unknown payment gateway.');

        $content = (string) $request->getContent();
        $expected = hash_hmac('sha256', $content, $secret);
        $provided = (string) $request->header('X-Webhook-Signature', '');
        abort_if($provided === '' || ! hash_equals($expected, $provided), 401, 'Invalid webhook signature.');

        $payload = json_decode($content, true);
        abort_if(! is_array($payload), 422, 'Webhook body must be JSON.');
        $ref = (string) ($payload['gateway_ref'] ?? '');
        abort_if($ref === '' || strlen($ref) > 128, 422, 'gateway_ref is required.');
        $status = (string) ($payload['status'] ?? '');
        abort_if(! in_array($status, ['success', 'failed', 'pending'], true), 422, 'Unknown webhook status.');

        $result = DB::transaction(function () use ($billing, $gateway, $ref, $payload, $status) {
            return $billing->handleWebhook($gateway, $ref, $payload, $status);
        });

        return response()->json([
            'data' => ['gateway_ref' => $result->gateway_ref, 'status' => $result->status],
        ]);
    }
}
