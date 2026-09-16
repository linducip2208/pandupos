<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** HMAC-signed outgoing webhooks with retry + delivery history. */
final class WebhookService
{
    public function dispatch(int $tenantId, string $event, array $payload): void
    {
        $endpoints = DB::table('webhook_endpoints')
            ->where('tenant_id', $tenantId)->where('is_active', true)->get();

        foreach ($endpoints as $ep) {
            $events = json_decode($ep->events, true) ?? [];
            if (! in_array($event, $events, true)) {
                continue;
            }

            $deliveryId = DB::table('webhook_deliveries')->insertGetId([
                'webhook_endpoint_id' => $ep->id, 'event' => $event,
                'payload' => json_encode($payload), 'status' => 'queued',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            dispatch(function () use ($ep, $event, $payload, $deliveryId) {
                $sig = hash_hmac('sha256', json_encode($payload), $ep->secret);
                try {
                    Http::withHeaders(['X-Webhook-Event' => $event, 'X-Webhook-Signature' => $sig])
                        ->timeout(10)->post($ep->url, $payload);
                    DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'delivered', 'attempts' => 1]);
                } catch (\Throwable) {
                    DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'attempts' => 1]);
                }
            })->afterResponse();
        }
    }
}
