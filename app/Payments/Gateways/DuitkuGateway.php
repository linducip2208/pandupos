<?php

namespace App\Payments\Gateways;

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
