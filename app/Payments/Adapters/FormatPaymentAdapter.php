<?php

namespace App\Payments\Adapters;

use App\Models\IntegrationProvider;
use App\Payments\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FormatPaymentAdapter implements PaymentGatewayInterface
{
    public function __construct(private readonly IntegrationProvider $provider)
    {
        if ($provider->integration_type !== 'payment' || ! $provider->is_active) {
            throw new InvalidArgumentException('Provider pembayaran tidak aktif atau tipe integrasinya tidak sesuai.');
        }
    }

    public function name(): string
    {
        return $this->provider->name;
    }

    public function createInvoice(array $params): array
    {
        $settings = $this->provider->settings ?? [];
        $path = trim((string) ($settings['create_path'] ?? ''), '/');
        if (! $this->provider->base_url || $path === '') {
            throw new InvalidArgumentException('Base URL dan create_path wajib dikonfigurasi.');
        }

        $headers = $this->provider->extra_headers ?? [];
        if ($this->provider->api_key_encrypted) {
            $headerName = (string) ($settings['auth_header'] ?? 'Authorization');
            $prefix = (string) ($settings['auth_prefix'] ?? 'Bearer ');
            $headers[$headerName] = $prefix.$this->provider->api_key_encrypted;
        }

        $response = Http::baseUrl(rtrim($this->provider->base_url, '/'))
            ->withHeaders($headers)
            ->timeout((int) ($settings['timeout'] ?? 15))
            ->post($path, $params)
            ->throw()
            ->json();

        return [
            'gateway' => $this->name(),
            'format' => $this->provider->api_format,
            'reference' => data_get($response, $settings['reference_path'] ?? 'reference', (string) Str::uuid()),
            'checkout_url' => data_get($response, $settings['checkout_url_path'] ?? 'checkout_url'),
            'status' => data_get($response, $settings['status_path'] ?? 'status', 'pending'),
            'raw' => $response,
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $algorithm = (string) data_get($this->provider->settings, 'signature_algorithm', 'sha256');
        if (! in_array($algorithm, hash_hmac_algos(), true)) {
            return false;
        }

        return hash_equals(hash_hmac($algorithm, $payload, $secret), $signature);
    }

    public function parseWebhook(array $payload): array
    {
        $settings = $this->provider->settings ?? [];

        return [
            'reference' => data_get($payload, $settings['webhook_reference_path'] ?? 'reference'),
            'status' => data_get($payload, $settings['webhook_status_path'] ?? 'status'),
            'amount' => data_get($payload, $settings['webhook_amount_path'] ?? 'amount'),
            'raw' => $payload,
        ];
    }
}
