<?php

namespace App\Integrations\Adapters;

use App\Models\IntegrationProvider;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class ImageGenericAdapter
{
    public function __construct(private readonly IntegrationProvider $provider) {}

    public function request(array $payload): array
    {
        $settings = $this->provider->settings ?? [];
        throw_if(blank($this->provider->base_url) || blank($settings['request_path'] ?? null), InvalidArgumentException::class, 'Base URL dan request_path wajib diisi.');
        $headers = $this->provider->extra_headers ?? [];
        if ($this->provider->api_key_encrypted) {
            $headers[$settings['auth_header'] ?? 'Authorization'] = ($settings['auth_prefix'] ?? 'Bearer ').$this->provider->api_key_encrypted;
        }

        return Http::baseUrl(rtrim($this->provider->base_url, '/'))->withHeaders($headers)->timeout((int) ($settings['timeout'] ?? 60))->post($settings['request_path'], $payload)->throw()->json();
    }
}
