<?php

namespace App\Payments\Gateways;

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
