<?php

namespace App\Payments\Gateways;

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
