<?php

namespace App\Payments\Gateways;

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
