<?php

namespace App\Payments;

interface PaymentGatewayInterface
{
    public function name(): string;

    /** Create a payment intent; return gateway reference + redirect/checkout URL. */
    public function createInvoice(array $params): array;

    /** Verify webhook signature. MUST be called before processing. */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool;

    public function parseWebhook(array $payload): array;
}
