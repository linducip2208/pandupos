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

class MidtransGateway extends BaseGateway
{
    public function name(): string
    {
        return 'midtrans';
    }

    public function createInvoice(array $params): array
    {
        return ['gateway' => $this->name(), 'reference' => $params['reference'] ?? uniqid('MT-'), 'status' => 'pending'];
    }
}

class XenditGateway extends BaseGateway
{
    public function name(): string
    {
        return 'xendit';
    }

    public function createInvoice(array $params): array
    {
        return ['gateway' => $this->name(), 'reference' => $params['reference'] ?? uniqid('XN-'), 'status' => 'pending'];
    }
}

class DuitkuGateway extends BaseGateway
{
    public function name(): string
    {
        return 'duitku';
    }

    public function createInvoice(array $params): array
    {
        return ['gateway' => $this->name(), 'reference' => $params['reference'] ?? uniqid('DK-'), 'status' => 'pending'];
    }
}

class TripayGateway extends BaseGateway
{
    public function name(): string
    {
        return 'tripay';
    }

    public function createInvoice(array $params): array
    {
        return ['gateway' => $this->name(), 'reference' => $params['reference'] ?? uniqid('TR-'), 'status' => 'pending'];
    }
}

class IPaymuGateway extends BaseGateway
{
    public function name(): string
    {
        return 'ipaymu';
    }

    public function createInvoice(array $params): array
    {
        return ['gateway' => $this->name(), 'reference' => $params['reference'] ?? uniqid('IP-'), 'status' => 'pending'];
    }
}
