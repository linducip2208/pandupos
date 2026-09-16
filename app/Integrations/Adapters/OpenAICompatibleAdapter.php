<?php

namespace App\Integrations\Adapters;

use App\Models\IntegrationProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class OpenAICompatibleAdapter
{
    public function __construct(private readonly IntegrationProvider $provider)
    {
        if ($provider->api_format !== 'openai_compatible' || ! $provider->is_active) {
            throw new InvalidArgumentException('Provider bukan format OpenAI-compatible yang aktif.');
        }
    }

    public function discoverModels(): array
    {
        $path = data_get($this->provider->settings, 'models_path', '/v1/models');
        $response = $this->client()->get($path)->throw()->json();

        return collect(data_get($response, data_get($this->provider->settings, 'models_data_path', 'data'), []))
            ->map(fn ($model) => is_array($model) ? ($model['id'] ?? $model['name'] ?? null) : $model)
            ->filter()->values()->all();
    }

    public function request(array $payload): array
    {
        $path = data_get($this->provider->settings, 'request_path');
        throw_if(blank($path), InvalidArgumentException::class, 'request_path wajib diisi oleh operator.');

        return $this->client()->post($path, $payload)->throw()->json();
    }

    private function client(): PendingRequest
    {
        throw_if(blank($this->provider->base_url), InvalidArgumentException::class, 'Base URL wajib diisi.');
        $headers = $this->provider->extra_headers ?? [];
        if ($this->provider->api_key_encrypted) {
            $name = data_get($this->provider->settings, 'auth_header', 'Authorization');
            $prefix = data_get($this->provider->settings, 'auth_prefix', 'Bearer ');
            $headers[$name] = $prefix.$this->provider->api_key_encrypted;
        }

        return Http::baseUrl(rtrim($this->provider->base_url, '/'))->withHeaders($headers)->timeout((int) data_get($this->provider->settings, 'timeout', 20));
    }
}
