<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** HMAC-signed outgoing webhooks with retry + delivery history. */
final class WebhookService
{
    public function __construct(private AuditService $audit) {}

    public function register(int $tenantId, array $data, ?int $actorId = null): int
    {
        $url = trim((string) ($data['url'] ?? ''));
        abort_unless(filter_var($url, FILTER_VALIDATE_URL), 422, 'Valid webhook URL is required.');
        $events = $data['events'] ?? [];
        abort_unless(is_array($events) && $events !== [], 422, 'At least one event is required.');
        $secret = $data['secret'] ?? Str::random(32);
        $id = DB::table('webhook_endpoints')->insertGetId([
            'tenant_id' => $tenantId, 'url' => $url, 'secret' => $secret,
            'events' => json_encode($events), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->log($tenantId, $actorId, 'webhook.registered', 'webhook_endpoint', $id, null, ['url' => $url, 'events' => $events]);

        return $id;
    }

    public function toggle(int $tenantId, int $endpointId, bool $active, ?int $actorId = null): void
    {
        DB::table('webhook_endpoints')->where('tenant_id', $tenantId)->whereKey($endpointId)
            ->update(['is_active' => $active, 'updated_at' => now()]);
        $this->audit->log($tenantId, $actorId, $active ? 'webhook.activated' : 'webhook.deactivated', 'webhook_endpoint', $endpointId);
    }

    /** @return array<int, array{endpoint_id:int,event:string,status:string,attempts:int,created_at:string}> */
    public function deliveries(int $tenantId, int $endpointId, int $limit = 50): array
    {
        return DB::table('webhook_deliveries as wd')
            ->join('webhook_endpoints as we', 'we.id', '=', 'wd.webhook_endpoint_id')
            ->where('we.tenant_id', $tenantId)->where('wd.webhook_endpoint_id', $endpointId)
            ->orderByDesc('wd.id')->limit($limit)
            ->select('wd.webhook_endpoint_id as endpoint_id', 'wd.event as event', 'wd.status as status', 'wd.attempts as attempts', 'wd.created_at as created_at')
            ->get()->all();
    }

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
                $this->attempt($ep, $event, $payload, $deliveryId);
            })->afterResponse();
        }
    }

    /** Retry a failed delivery up to three times with exponential backoff. */
    public function retry(int $tenantId, int $deliveryId): bool
    {
        $row = DB::table('webhook_deliveries as wd')
            ->join('webhook_endpoints as we', 'we.id', '=', 'wd.webhook_endpoint_id')
            ->where('we.tenant_id', $tenantId)->where('wd.id', $deliveryId)
            ->select('we.*', 'wd.id as delivery_id', 'wd.payload as payload', 'wd.event as event', 'wd.attempts as attempts')
            ->first();
        if (! $row) {
            return false;
        }
        $attempts = (int) ($row->attempts ?? 0);
        if ($attempts >= 3) {
            DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'dead_letter']);

            return false;
        }

        return $this->attempt((object) [
            'id' => $row->id, 'url' => $row->url, 'secret' => $row->secret,
        ], $row->event, json_decode($row->payload, true) ?? [], $deliveryId);
    }

    private function attempt(object $ep, string $event, array $payload, int $deliveryId): bool
    {
        $sig = hash_hmac('sha256', json_encode($payload), $ep->secret);
        try {
            Http::withHeaders(['X-Webhook-Event' => $event, 'X-Webhook-Signature' => $sig])
                ->timeout(10)->retry(2, 500)->post($ep->url, $payload);
            DB::table('webhook_deliveries')->where('id', $deliveryId)->update([
                'status' => 'delivered', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
            ]);

            return true;
        } catch (\Throwable) {
            DB::table('webhook_deliveries')->where('id', $deliveryId)->update([
                'status' => 'failed', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
            ]);

            return false;
        }
    }
}
