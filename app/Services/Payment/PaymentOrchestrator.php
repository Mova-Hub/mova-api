<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Adapters\MtnCongoAdapter;
use App\Services\Payment\Adapters\AirtelCongoAdapter;
use App\Services\Payment\Adapters\MovaWalletAdapter;
use InvalidArgumentException;

class PaymentOrchestrator
{
    public function resolve(string $method): PaymentGatewayInterface
    {
        // PHP 8+ match expression is perfect for this
        return match ($method) {
            'mova_wallet' => new MovaWalletAdapter(),
            'mtn_cg'      => new MtnCongoAdapter(),
            'airtel_cg'   => new AirtelCongoAdapter(),
            default       => throw new InvalidArgumentException("Le moyen de paiement [{$method}] n'est pas supporté."),
        };
    }
}
