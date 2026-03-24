<?php

namespace App\Services\Payment\Adapters;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Transaction;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\DB;

class MovaWalletAdapter implements PaymentGatewayInterface
{
    /**
     * @param int $amount
     * @param string $reference (C'est l'ID de notre Transaction UUID)
     * @param string $description
     * @param array $meta
     * @return array
     * @throws Exception
     */
    public function charge(int $amount, string $reference, string $description, array $meta = []): array
    {
        // 1. On retrouve la transaction associée
        $transaction = Transaction::findOrFail($reference);
        $user = $transaction->user;

        // On s'assure que l'utilisateur a bien un portefeuille
        $wallet = $user->wallet;
        if (!$wallet) {
            throw new Exception("L'utilisateur n'a pas de portefeuille Mova.");
        }

        // 2. Transaction DB avec Verrouillage (Très important !)
        return DB::transaction(function () use ($wallet, $amount) {

            // lockForUpdate() empêche toute autre requête de modifier ce wallet
            // le temps que ce bout de code se termine. Fini les doubles dépenses !
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if (!$lockedWallet->hasSufficientFunds($amount)) {
                throw new Exception("Solde insuffisant dans votre Mova Wallet.");
            }

            // 3. On déduit le montant
            $lockedWallet->decrement('balance', $amount);

            // 4. On renvoie le succès à notre Controller
            return [
                'success' => true,
                'provider_reference' => 'MOVA-WALLET-' . uniqid(), // Une fausse ref externe pour garder la consistance
                'message' => 'Paiement effectué avec succès.'
            ];
        });
    }

    /**
     * Vérifie le statut d'un paiement (Utile pour les requêtes futures)
     */
    public function verify(string $reference): array
    {
        $transaction = Transaction::findOrFail($reference);

        return [
            'success' => $transaction->status === 'successful',
            'status' => $transaction->status,
            'amount' => $transaction->amount,
        ];
    }
}
