<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Something went wrong collecting money, in a way a client should be told about.
 *
 * The message is rendered straight into the payment sheet, so it is written in
 * French and never carries a provider status code — "ECONNRESET" and
 * "MERCHANT_NOT_FOUND" are for the log and for Sentry. A driver that leaks one
 * into here has made an internal failure look like the client's fault.
 */
class PaymentException extends RuntimeException
{
}
