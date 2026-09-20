<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WoConnection;
use App\Services\WooSyncService;
use Illuminate\Http\Request;

/** WooCommerce order webhooks: signature-verified, tenant-scoped, idempotent. */
class WooWebhookController extends Controller
{
    public function handle(Request $request, WooSyncService $sync, int $connection)
    {
        $conn = WoConnection::withoutGlobalScopes()->findOrFail($connection);
        abort_if($conn->status !== 'active', 404);
        $signature = (string) $request->header('X-WC-Webhook-Signature', '');
        abort_if($signature === '' || ! $sync->verifyWebhookSignature($conn, $request->getContent(), $signature), 401, 'Invalid webhook signature.');
        $topic = (string) $request->header('X-WC-Webhook-Topic', '');
        $payload = $request->json()->all();
        if (! is_array($payload) || ! str_starts_with($topic, 'order.')) {
            return response()->json(['message' => 'Ignored.'], 422);
        }
        $imported = $sync->importSingleOrder($conn, $payload);

        return response()->json(['imported' => $imported]);
    }
}
