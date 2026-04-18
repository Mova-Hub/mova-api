<?php

namespace App\Services;

use Twilio\Rest\Client as TwilioClient;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected ?TwilioClient $client = null;
    protected ?string $fromNumber;

    public function __construct()
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $this->fromNumber = config('services.twilio.from');

        if ($sid && $token && $this->fromNumber) {
            $this->client = new TwilioClient($sid, $token);
        } else {
            Log::warning('Twilio credentials missing in config/services.php');
        }
    }

    /**
     * Send a generic SMS message.
     */
    public function send(string $to, string $message): bool
    {
        if (!$this->client) {
            return false;
        }

        try {
            $this->client->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $message
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Twilio SMS Error for {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a standardized OTP message for Mova.
     */
    public function sendOtp(string $to, string|int $otp): bool
    {
        $message = "Mova: Votre code de vérification est {$otp}. Il est valable 10 minutes.";

        return $this->send($to, $message);
    }
}
