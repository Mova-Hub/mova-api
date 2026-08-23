<?php

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A credit operation that could not be carried out.
 *
 * Message is French and client-safe — "Solde Mova insuffisant" is shown
 * verbatim in the payment sheet.
 */
class WalletException extends RuntimeException
{
}
