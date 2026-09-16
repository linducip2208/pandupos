<?php

namespace App\Payments\Gateways;

use App\Payments\PaymentGatewayInterface;

abstract class BaseGateway implements PaymentGatewayInterface
{
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(array $payload): array
    {
        return $payload;
    }
}
