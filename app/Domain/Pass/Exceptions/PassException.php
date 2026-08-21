<?php

namespace App\Domain\Pass\Exceptions;

use RuntimeException;

/**
 * A Pass operation that cannot proceed for a business reason.
 *
 * Carries a French message safe to show a customer and an HTTP status, so
 * controllers translate rather than invent. The messages are deliberately
 * uniform where they touch someone else's card — see CardService for why.
 */
class PassException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'pass_error',
    ) {
        parent::__construct($message);
    }

    public static function cardNotFound(): self
    {
        return new self('Carte introuvable. Vérifiez le numéro inscrit au dos.', 404, 'card_not_found');
    }

    public static function cardUnavailable(): self
    {
        return new self(
            'Cette carte ne peut pas être activée. Contactez un guichet Mova.',
            409,
            'card_unavailable',
        );
    }

    public static function cardBlocked(): self
    {
        return new self('Cette carte a été bloquée.', 409, 'card_blocked');
    }

    public static function noSigningKey(): self
    {
        return new self('Signature indisponible.', 503, 'signing_unavailable');
    }

    public static function planUnavailable(): self
    {
        return new self('Cette formule n’est plus disponible.', 422, 'plan_unavailable');
    }
}
