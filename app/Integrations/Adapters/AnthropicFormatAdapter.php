<?php

namespace App\Integrations\Adapters;

use App\Models\IntegrationProvider;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class AnthropicFormatAdapter
{
    public function __construct(private readonly IntegrationProvider $provider) {}

    public function request(array $payload): array
    {
        throw_unless($this->provider->api_format === 'anthropic_format' && $this->provider->is_active, InvalidArgumentException::class, 'Format provider tidak sesuai.');
        $settings = $this->provider->settings ?? [];
        throw_if(blank($this->provider->base_url) || blank($settings['request_path'] ?? null), InvalidArgumentException::class, 'Base URL dan request_path wajib diisi.');
        $headers = $this->provider->extra_headers ?? [];
        if ($this->provider->api_key_encrypted) {
            $headers[$settings['auth_header'] ?? 'x-api-key'] = ($settings['auth_prefix'] ?? '').$this->provider->api_key_encrypted;
        }

        return Http::baseUrl(rtrim($this->provider->base_url, '/'))->withHeaders($headers)->timeout((int) ($settings['timeout'] ?? 20))->post($settings['request_path'], $payload)->throw()->json();
    }
}
