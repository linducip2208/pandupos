<?php

namespace App\Services\WooCommerce;

/** In-memory WooCommerce stand-in for tests, demos and CI (no live store). */
final class FakeWooClient implements WooClientInterface
{
    /** @var array<int, array> */
    public array $products = [];

    /** @var array<int, array> */
    public array $orders = [];

    /** @var array<int, array> */
    public array $customers = [];

    /** @var array<int, array{method:string,path:string,payload:array}> */
    public array $calls = [];

    private int $nextId = 1000;

    public function get(string $path, array $query = []): array
    {
        $this->calls[] = ['method' => 'GET', 'path' => $path, 'payload' => $query];
        if ($path === 'orders') {
            return ['status' => 200, 'body' => array_values($this->orders)];
        }
        if ($path === 'customers') {
            return ['status' => 200, 'body' => array_values($this->customers)];
        }
        if (preg_match('#^products/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            abort_unless(isset($this->products[$id]), 404, 'Fake product not found.');

            return ['status' => 200, 'body' => $this->products[$id]];
        }

        return ['status' => 200, 'body' => []];
    }

    public function post(string $path, array $payload): array
    {
        $this->calls[] = ['method' => 'POST', 'path' => $path, 'payload' => $payload];
        if ($path === 'products') {
            $id = $this->nextId++;
            $this->products[$id] = array_merge(['id' => $id, 'stock_quantity' => 0], $payload);

            return ['status' => 201, 'body' => $this->products[$id]];
        }

        return ['status' => 201, 'body' => $payload];
    }

    public function put(string $path, array $payload): array
    {
        $this->calls[] = ['method' => 'PUT', 'path' => $path, 'payload' => $payload];
        if (preg_match('#^products/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            abort_unless(isset($this->products[$id]), 404, 'Fake product not found.');
            $this->products[$id] = array_merge($this->products[$id], $payload);

            return ['status' => 200, 'body' => $this->products[$id]];
        }

        return ['status' => 200, 'body' => $payload];
    }

    public function seedOrder(array $order): int
    {
        $id = $order['id'] ?? $this->nextId++;
        $this->orders[$id] = array_merge(['id' => $id], $order);

        return $id;
    }
}
