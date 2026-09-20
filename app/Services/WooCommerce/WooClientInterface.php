<?php

namespace App\Services\WooCommerce;

/** Transport abstraction: real HTTP client in prod, fake in tests/demos. */
interface WooClientInterface
{
    /** @return array{status:int,body:array} */
    public function get(string $path, array $query = []): array;

    /** @return array{status:int,body:array} */
    public function post(string $path, array $payload): array;

    /** @return array{status:int,body:array} */
    public function put(string $path, array $payload): array;
}
