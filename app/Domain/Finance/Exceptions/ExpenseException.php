<?php

namespace App\Domain\Finance\Exceptions;

/**
 * A refusal the person recording an expense is meant to read.
 *
 * Every message on this exception is shown to an operator verbatim, so it says
 * what is wrong and what the remaining room is, never "validation failed". The
 * controller maps it to a 422; it is not an error worth a Sentry event, because
 * it means the rules worked.
 */
class ExpenseException extends \RuntimeException {}
