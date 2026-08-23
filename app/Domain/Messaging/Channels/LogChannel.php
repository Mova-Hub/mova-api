<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Contracts\MessagingChannel;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The development channel, and the default.
 *
 * Writes to the log instead of sending, so a developer clicking through
 * registration does not need Infobip credentials — and, more importantly, does
 * not text a real person from a seeded fixture.
 *
 * **It masks the destination and never writes the body of an OTP.** That is not
 * excessive for a log channel: `LOG_LEVEL=debug` is the default in development,
 * these lines end up in files that get shared in bug reports, and two plaintext
 * OTP log lines were already found and removed from this codebase once.
 */
class LogChannel implements MessagingChannel
{
    public function supports(string $kind): bool
    {
        return true;
    }

    public function send(string $to, string $kind, string $body, array $variables = []): SendResult
    {
        Log::info('Message (log channel — nothing sent)', [
            'kind' => $kind,
            'to' => $this->mask($to),
            // A code in a message body is still a code. Length is enough to
            // confirm the template rendered; the content is not needed.
            'body_length' => mb_strlen($body),
            'preview' => $this->redact($body),
        ]);

        return SendResult::sent('log', 'log-' . Str::random(8));
    }

    public function healthCheck(array $credentials): SendResult
    {
        return SendResult::sent('log', 'Canal de développement — aucun message n’est envoyé.');
    }

    private function mask(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }

    /** Strips anything that looks like a 4–8 digit code. */
    private function redact(string $body): string
    {
        return preg_replace('/\b\d{4,8}\b/', '[code]', mb_substr($body, 0, 120)) ?? '';
    }
}
