<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    /**
     * Real push: validates client mutation UUID + idempotency, stores idempotency key.
     * Financial entities are append-only; stock is movement-based, never overwritten.
     */
    public function push(Request $request)
    {
        $data = $request->validate([
            'device_uuid' => 'required|string|max:64',
            'mutations' => 'required|array|min:1|max:100',
            'mutations.*.uuid' => 'required|string|max:64',
            'mutations.*.entity' => 'required|string|max:64',
            'mutations.*.operation' => 'required|string|max:16',
            'mutations.*.payload' => 'nullable|array',
            'mutations.*.client_timestamp' => 'nullable|string',
        ]);

        $tenantId = TenantContext::id();
        $results = [];

        DB::transaction(function () use ($data, $tenantId, &$results) {
            $device = DB::table('devices')->where('tenant_id', $tenantId)
                ->where(function ($q) use ($data) {
                    $q->where('uuid', $data['device_uuid'])->orWhere('device_id', $data['device_uuid']);
                })->first();
            if (! $device) {
                $deviceId = DB::table('devices')->insertGetId([
                    'tenant_id' => $tenantId, 'uuid' => $data['device_uuid'], 'device_id' => $data['device_uuid'],
                    'name' => $data['device_uuid'], 'last_sync_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $deviceId = $device->id;
                DB::table('devices')->where('id', $deviceId)->update(['last_sync_at' => now(), 'updated_at' => now()]);
            }

            foreach ($data['mutations'] as $m) {
                $key = $tenantId.':'.$m['entity'].':'.$m['uuid'];
                $existing = DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('key', $key)->first();
                if ($existing) {
                    $results[] = ['uuid' => $m['uuid'], 'status' => 'duplicate', 'conflict' => 'already_applied'];

                    continue;
                }

                // Completed invoices are immutable — reject overwrites.
                if (in_array($m['entity'], ['sales_invoice', 'payment'], true) && ($m['operation'] ?? '') === 'update') {
                    $results[] = ['uuid' => $m['uuid'], 'status' => 'conflict', 'conflict' => 'immutable_entity_use_void_or_return'];

                    continue;
                }

                DB::table('idempotency_keys')->insert([
                    'tenant_id' => $tenantId, 'key' => $key,
                    'response' => json_encode(['device_id' => $deviceId]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('server_change_logs')->insert([
                    'tenant_id' => $tenantId, 'entity' => $m['entity'], 'entity_uuid' => $m['uuid'],
                    'entity_type' => $m['entity'], 'entity_id' => 0,
                    'operation' => $m['operation'], 'changed_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $results[] = ['uuid' => $m['uuid'], 'status' => 'applied'];
            }
        });

        return response()->json(['data' => $results]);
    }

    public function pull(Request $request)
    {
        $request->validate(['since' => 'nullable|string', 'cursor' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:500']);
        $tenantId = TenantContext::id();
        $cursor = (int) ($request->get('cursor', 0));
        $limit = (int) ($request->get('limit', 200));

        $q = DB::table('server_change_logs')->where('tenant_id', $tenantId)->orderBy('id');
        if ($since = $request->get('since')) {
            $q->where('changed_at', '>', $since);
        }
        if ($cursor > 0) {
            $q->where('id', '>', $cursor);
        }
        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $changes = $rows->take($limit)->values();
        $nextCursor = $changes->isNotEmpty() ? $changes->last()->id : $cursor;

        return response()->json(['data' => $changes, 'meta' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore]]);
    }
}
