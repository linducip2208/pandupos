<?php

namespace App\Services;

use App\Models\AiProviderConfig;
use App\Models\AiUsage;
use App\Models\Warehouse;
use App\Services\AI\AiProviderInterface;
use App\Services\AI\AnthropicProvider;
use App\Services\AI\GoogleProvider;
use App\Services\AI\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * AI assistant hub: provider-agnostic completions with encrypted keys,
 * monthly token budgets, per-feature usage metering, and deterministic
 * inventory anomaly detection that needs no LLM at all.
 */
final class AiService
{
    public const FEATURES = ['sales_assistant', 'report_assistant', 'inventory_assistant', 'customer_assistant', 'document_assistant', 'anomaly_detection'];

    private const BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
    ];

    public function __construct(private AuditService $audit) {}

    public function configure(int $tenantId, array $data, ?int $actorId = null): AiProviderConfig
    {
        $provider = $data['provider'] ?? null;
        abort_unless(in_array($provider, AiProviderConfig::PROVIDERS, true), 422, 'Invalid AI provider.');
        $model = trim((string) ($data['model'] ?? ''));
        abort_if($model === '', 422, 'Model is required.');
        $key = trim((string) ($data['api_key'] ?? ''));
        abort_if($key === '', 422, 'API key is required.');
        $baseUrl = trim((string) ($data['base_url'] ?? self::BASE_URLS[$provider] ?? ''));
        if (in_array($provider, ['openrouter', 'custom'], true)) {
            abort_unless(filter_var($baseUrl, FILTER_VALIDATE_URL), 422, 'Base URL is required for this provider.');
        }

        $config = AiProviderConfig::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => $provider],
            ['base_url' => $baseUrl ?: null, 'api_key' => Crypt::encryptString($key), 'model' => $model,
                'monthly_token_cap' => isset($data['monthly_token_cap']) ? max(1, (int) $data['monthly_token_cap']) : null,
                'is_active' => true]
        );
        $this->audit->log($tenantId, $actorId, 'ai.provider.configured', AiProviderConfig::class, $config->id, null, ['provider' => $provider, 'model' => $model]);

        return $config;
    }

    /** @param array<int, array{role:string,content:string}> $messages */
    public function ask(int $tenantId, string $feature, array $messages, ?int $actorId = null, ?AiProviderInterface $provider = null): string
    {
        abort_unless(in_array($feature, self::FEATURES, true), 422, 'Invalid assistant feature.');
        abort_if($messages === [], 422, 'Messages are required.');
        $config = AiProviderConfig::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('id')->firstOrFail();
        if ($config->monthly_token_cap) {
            $used = (int) AiUsage::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->startOfMonth())->sum(DB::raw('tokens_in + tokens_out'));
            abort_if($used >= (int) $config->monthly_token_cap, 422, 'Monthly AI token budget exhausted.');
        }
        $provider ??= $this->buildProvider($config);
        $result = $provider->complete($messages);
        AiUsage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'provider' => $config->provider, 'feature' => $feature,
            'tokens_in' => $result->tokensIn, 'tokens_out' => $result->tokensOut,
        ]);
        $this->audit->log($tenantId, $actorId, 'ai.completion', AiProviderConfig::class, $config->id, null, ['feature' => $feature, 'tokens' => $result->totalTokens()]);

        return $result->content;
    }

    /** Deterministic anomaly scan over real inventory data (no LLM needed). */
    public function detectAnomalies(int $tenantId): array
    {
        $findings = [];
        $warehouseId = $this->defaultWarehouse($tenantId);
        if ($warehouseId > 0) {
            $rows = DB::table('products')->join('product_variants', 'product_variants.product_id', '=', 'products.id')
                ->where('products.tenant_id', $tenantId)
                ->select('products.name', 'product_variants.id AS variant_id', 'product_variants.sku', 'products.alert_quantity')
                ->limit(200)->get();
            foreach ($rows as $row) {
                $onHand = app(StockService::class)->onHand($tenantId, $warehouseId, (int) $row->variant_id);
                if ($onHand <= (float) $row->alert_quantity) {
                    $findings[] = ['type' => 'low_stock', 'message' => "{$row->name} ({$row->sku}) on hand {$onHand} at/below reorder {$row->alert_quantity}"];
                }
            }
        }
        // sales_lines carries no tenant column: scope through its invoice.
        $dead = DB::table('product_variants')->where('tenant_id', $tenantId)
            ->whereNotExists(function ($q) use ($tenantId) {
                $q->select(DB::raw(1))->from('sales_lines')
                    ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_lines.sales_invoice_id')
                    ->where('sales_invoices.tenant_id', $tenantId)
                    ->whereColumn('sales_lines.product_variant_id', 'product_variants.id')
                    ->where('sales_lines.created_at', '>=', now()->subDays(30));
            })->limit(50)->pluck('sku')->all();
        foreach ($dead as $sku) {
            $findings[] = ['type' => 'dead_stock', 'message' => "Variant {$sku} had no sales in 30 days"];
        }

        return $findings;
    }

    /** @return array{used:int,cap:?int} */
    public function budget(int $tenantId): array
    {
        $config = AiProviderConfig::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('id')->first();
        $used = (int) AiUsage::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('created_at', '>=', now()->startOfMonth())->sum(DB::raw('tokens_in + tokens_out'));

        return ['used' => $used, 'cap' => $config?->monthly_token_cap];
    }

    public function buildProvider(AiProviderConfig $config, ?AiProviderInterface $fake = null): AiProviderInterface
    {
        if ($fake) {
            return $fake;
        }
        $key = Crypt::decryptString($config->api_key);

        return match ($config->provider) {
            'anthropic' => new AnthropicProvider($key, $config->model),
            'google' => new GoogleProvider($key, $config->model),
            default => new OpenAiCompatibleProvider($config->base_url ?: (self::BASE_URLS[$config->provider] ?? ''), $key, $config->model),
        };
    }

    private function defaultWarehouse(int $tenantId): int
    {
        return (int) Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->value('id');
    }
}
