<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EcommerceOrder;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WoConnection;
use App\Models\WoProductLink;
use App\Models\WoSyncLog;
use App\Services\WooCommerce\HttpWooClient;
use App\Services\WooCommerce\WooClientInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * WooCommerce connector: encrypted credentials, product/inventory push,
 * order/customer pull into ecommerce orders, signed idempotent webhooks.
 * Every sync step is logged; secrets never touch logs.
 */
final class WooSyncService
{
    public function __construct(private AuditService $audit) {}

    public function createConnection(int $tenantId, array $data, ?int $actorId = null): WoConnection
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Connection name is required.');
        $url = trim((string) ($data['store_url'] ?? ''));
        abort_unless(filter_var($url, FILTER_VALIDATE_URL), 422, 'Store URL is invalid.');
        abort_if(trim((string) ($data['consumer_key'] ?? '')) === '' || trim((string) ($data['consumer_secret'] ?? '')) === '', 422, 'Consumer key and secret are required.');

        $connection = WoConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'store_url' => $url,
            'consumer_key' => Crypt::encryptString($data['consumer_key']),
            'consumer_secret' => Crypt::encryptString($data['consumer_secret']),
            'status' => 'active',
        ]);
        $this->audit->log($tenantId, $actorId, 'woo.connection.created', WoConnection::class, $connection->id, null, ['name' => $name, 'store_url' => $url]);

        return $connection;
    }

    public function clientFor(WoConnection $connection, ?WooClientInterface $override = null): WooClientInterface
    {
        if ($override) {
            return $override;
        }

        return new HttpWooClient($connection->store_url, Crypt::decryptString($connection->consumer_key), Crypt::decryptString($connection->consumer_secret));
    }

    /** Push a local variant as a Woo product (create once, update after). */
    public function pushProduct(WoConnection $connection, int $variantId, ?WooClientInterface $client = null, ?int $actorId = null): WoProductLink
    {
        $connection = $this->active($connection->id);
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $connection->tenant_id)->findOrFail($variantId);
        $client ??= $this->clientFor($connection);
        $payload = [
            'name' => $variant->product?->name.' '.$variant->name,
            'sku' => $variant->sku, 'regular_price' => (string) $variant->sell_price,
            'manage_stock' => true,
        ];

        return DB::transaction(function () use ($connection, $variant, $client, $payload, $actorId) {
            $link = WoProductLink::withoutGlobalScopes()->where('connection_id', $connection->id)->where('local_variant_id', $variant->id)->first();
            try {
                if ($link) {
                    $res = $client->put('products/'.$link->woo_product_id, $payload);
                } else {
                    $res = $client->post('products', $payload);
                    $link = WoProductLink::withoutGlobalScopes()->create([
                        'tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id,
                        'local_variant_id' => $variant->id, 'woo_product_id' => $res['body']['id'],
                    ]);
                }
                $this->log($connection, 'push', 'product', (string) ($res['body']['id'] ?? ''), 'variant:'.$variant->id, 'success');
                $connection->update(['last_sync_at' => now(), 'last_error' => null]);
            } catch (\Throwable $e) {
                $connection->update(['status' => 'error', 'last_error' => substr($e->getMessage(), 0, 500)]);
                $this->log($connection, 'push', 'product', '', 'variant:'.$variant->id, 'failed', 'Push failed without credentials in log.');
                throw $e;
            }
            $this->audit->log($connection->tenant_id, $actorId, 'woo.product.pushed', WoProductLink::class, $link->id, null, ['woo_product_id' => $link->woo_product_id]);

            return $link;
        });
    }

    /** Push on-hand stock for every linked variant of the connection. */
    public function pushInventory(WoConnection $connection, ?WooClientInterface $client = null, ?int $actorId = null): int
    {
        $connection = $this->active($connection->id);
        $client ??= $this->clientFor($connection);
        $pushed = 0;
        foreach ($connection->links()->with('variant')->get() as $link) {
            $qty = (int) app(StockService::class)->onHand($connection->tenant_id, $this->defaultWarehouse($connection->tenant_id), $link->local_variant_id);
            try {
                $client->put('products/'.$link->woo_product_id, ['stock_quantity' => $qty, 'manage_stock' => true]);
                $this->log($connection, 'push', 'inventory', (string) $link->woo_product_id, 'variant:'.$link->local_variant_id, 'success');
                $pushed++;
            } catch (\Throwable $e) {
                $this->log($connection, 'push', 'inventory', (string) $link->woo_product_id, 'variant:'.$link->local_variant_id, 'failed', 'Inventory push failed.');
            }
        }
        $connection->update(['last_sync_at' => now()]);
        $this->audit->log($connection->tenant_id, $actorId, 'woo.inventory.pushed', WoConnection::class, $connection->id, null, ['pushed' => $pushed]);

        return $pushed;
    }

    /** Pull remote orders into local ecommerce orders (idempotent by external id). */
    public function pullOrders(WoConnection $connection, ?WooClientInterface $client = null, ?int $actorId = null): int
    {
        $connection = $this->active($connection->id);
        $client ??= $this->clientFor($connection);
        $res = $client->get('orders', ['per_page' => 50, 'status' => 'processing']);
        $imported = 0;
        foreach ($res['body'] as $remote) {
            if ($this->importSingleOrder($connection, $remote)) {
                $imported++;
            }
        }
        $connection->update(['last_sync_at' => now()]);
        $this->audit->log($connection->tenant_id, $actorId, 'woo.orders.pulled', WoConnection::class, $connection->id, null, ['imported' => $imported]);

        return $imported;
    }

    /** Import one remote order; returns true on success (replays are no-ops). */
    public function importSingleOrder(WoConnection $connection, array $remote): bool
    {
        $externalId = (string) ($remote['id'] ?? '');
        if ($externalId === '') {
            return false;
        }
        if (WoSyncLog::withoutGlobalScopes()->where('tenant_id', $connection->tenant_id)->where('connection_id', $connection->id)->where('entity', 'order')->where('external_id', $externalId)->where('status', 'success')->exists()) {
            return false; // idempotent replay
        }
        try {
            $this->importOrder($connection, $remote);
            $this->log($connection, 'pull', 'order', $externalId, '', 'success');

            return true;
        } catch (\Throwable $e) {
            $this->log($connection, 'pull', 'order', $externalId, '', 'failed', substr($e->getMessage(), 0, 500));

            return false;
        }
    }

    /** Pull remote customers into local contacts (upsert by email). */
    public function pullCustomers(WoConnection $connection, ?WooClientInterface $client = null, ?int $actorId = null): int
    {
        $connection = $this->active($connection->id);
        $client ??= $this->clientFor($connection);
        $res = $client->get('customers', ['per_page' => 50]);
        $count = 0;
        foreach ($res['body'] as $remote) {
            $email = strtolower(trim((string) ($remote['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->log($connection, 'pull', 'customer', (string) ($remote['id'] ?? ''), '', 'failed', 'Missing email.');

                continue;
            }
            Contact::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $connection->tenant_id, 'email' => $email],
                ['type' => 'customer', 'name' => trim(($remote['first_name'] ?? '').' '.($remote['last_name'] ?? '')) ?: $email]
            );
            $this->log($connection, 'pull', 'customer', (string) ($remote['id'] ?? ''), $email, 'success');
            $count++;
        }
        $connection->update(['last_sync_at' => now()]);

        return $count;
    }

    /** Verify a WooCommerce webhook signature (base64 HMAC-SHA256). */
    public function verifyWebhookSignature(WoConnection $connection, string $payload, string $signature): bool
    {
        $secret = Crypt::decryptString($connection->consumer_secret);

        return hash_equals(base64_encode(hash_hmac('sha256', $payload, $secret, true)), $signature);
    }

    private function importOrder(WoConnection $connection, array $remote): EcommerceOrder
    {
        return DB::transaction(function () use ($connection, $remote) {
            $tenantId = $connection->tenant_id;
            $email = strtolower(trim((string) ($remote['billing']['email'] ?? '')));
            abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Remote order has no valid email.');
            $lines = [];
            $subtotal = 0.0;
            foreach ($remote['line_items'] ?? [] as $item) {
                $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('sku', $item['sku'] ?? '')->first();
                abort_if(! $variant, 422, 'Unmapped SKU ['.($item['sku'] ?? '?').']; link products before importing.');
                $qty = round((float) ($item['quantity'] ?? 0), 3);
                $price = round((float) ($item['price'] ?? $variant->sell_price), 2);
                abort_if($qty <= 0, 422, 'Invalid remote line quantity.');
                $lines[] = ['variant_id' => $variant->id, 'quantity' => $qty, 'price' => $price];
                $subtotal = round($subtotal + $qty * $price, 2);
            }
            abort_if($lines === [], 422, 'Remote order has no importable lines.');
            $contact = Contact::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'email' => $email],
                ['type' => 'customer', 'name' => trim(($remote['billing']['first_name'] ?? '').' '.($remote['billing']['last_name'] ?? '')) ?: $email]
            );
            $order = EcommerceOrder::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'number' => 'WOO-'.$remote['id'], 'contact_id' => $contact->id,
                'email' => $email, 'recipient' => $contact->name, 'phone' => (string) ($remote['billing']['phone'] ?? ''),
                'address' => (string) ($remote['billing']['address_1'] ?? ''), 'city' => (string) ($remote['billing']['city'] ?? ''),
                'shipping_method' => 'regular', 'shipping_fee' => 0,
                'status' => EcommerceOrder::PENDING, 'subtotal' => $subtotal, 'discount' => 0, 'total' => $subtotal,
            ]);
            foreach ($lines as $row) {
                $order->lines()->create([
                    'tenant_id' => $tenantId, 'product_variant_id' => $row['variant_id'],
                    'quantity' => $row['quantity'], 'unit_price' => $row['price'],
                ]);
            }

            return $order;
        });
    }

    private function active(int $connectionId): WoConnection
    {
        $connection = WoConnection::withoutGlobalScopes()->findOrFail($connectionId);
        abort_if($connection->status !== 'active', 422, 'Connection is not active.');

        return $connection;
    }

    private function defaultWarehouse(int $tenantId): int
    {
        return (int) Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->value('id');
    }

    private function log(WoConnection $connection, string $direction, string $entity, string $externalId, string $localRef, string $status, ?string $message = null): void
    {
        WoSyncLog::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id,
            'direction' => $direction, 'entity' => $entity, 'external_id' => $externalId ?: null,
            'local_reference' => $localRef ?: null, 'status' => $status, 'message' => $message,
        ]);
    }
}
