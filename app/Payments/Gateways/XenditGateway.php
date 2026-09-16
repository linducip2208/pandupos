<?php

namespace App\Payments\Gateways;

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
