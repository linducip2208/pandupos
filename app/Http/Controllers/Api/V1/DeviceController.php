<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index()
    {
        $tenantId = TenantContext::id();

        return response()->json(['data' => Device::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('id')->get()]);
    }

    /**
     * Revoke a sync device. Append-only: revoked_at is set once and never cleared.
     * A revoked device is locked out of the sync write path (423 from push).
     */
    public function revoke(Request $request)
    {
        $tenantId = TenantContext::id();
        $data = $request->validate([
            'device_uuid' => 'required|string|max:64',
            'reason' => 'nullable|string|max:255',
        ]);

        $device = Device::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($data) {
                $q->where('uuid', $data['device_uuid'])->orWhere('device_id', $data['device_uuid']);
            })
            ->first();
        abort_unless($device, 404, 'Device not found.');

        $device->update([
            'revoked_at' => $device->revoked_at ?? now(),
        ]);

        return response()->json([
            'data' => ['device_uuid' => $device->uuid ?? $device->device_id, 'revoked_at' => $device->revoked_at->toIso8601String()],
        ]);
    }
}
