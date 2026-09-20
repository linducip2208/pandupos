<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Http;

/** Live WooCommerce REST transport: basic-auth keys, timeouts, error mapping. */
final class HttpWooClient implements WooClientInterface
{
    public function __construct(private string $storeUrl, private string $key, private string $secret) {}

    /** @return array{status:int,body:array} */
    private function call(string $method, string $path, array $data = []): array
    {
        $url = rtrim($this->storeUrl, '/').'/wp-json/wc/v3/'.ltrim($path, '/');
        $pending = Http::withBasicAuth($this->key, $this->secret)->timeout(20)->acceptJson();
        $response = match (strtolower($method)) {
            'post' => $pending->post($url, $data),
            'put' => $pending->put($url, $data),
            default => $pending->get($url, $data),
        };
        if ($response->failed()) {
            throw new WooTransportException('WooCommerce request failed: HTTP '.$response->status());
        }

        return ['status' => $response->status(), 'body' => $response->json() ?? []];
    }

    public function get(string $path, array $query = []): array
    {
        return $this->call('get', $path, $query);
    }

    public function post(string $path, array $payload): array
    {
        return $this->call('post', $path, $payload);
    }

    public function put(string $path, array $payload): array
    {
        return $this->call('put', $path, $payload);
    }
}
