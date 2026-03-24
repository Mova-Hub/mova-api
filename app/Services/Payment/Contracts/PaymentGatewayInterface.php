<?php

namespace App\Services\Payment\Contracts;

interface PaymentGatewayInterface
{
    /**
     * @param int $amount Le montant (ex: 5000)
     * @param string $reference L'ID de ta transaction (ex: TXN-12345)
     * @param string $description Le motif visible par l'utilisateur (ex: "Billet Brazza-Pointe Noire")
     * @param array $meta Données supplémentaires (ex: numéro de téléphone)
     */
    public function charge(int $amount, string $reference, string $description, array $meta = []): array;

    public function verify(string $reference): array;
}
