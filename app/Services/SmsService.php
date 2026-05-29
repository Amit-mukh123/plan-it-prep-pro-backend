<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send($phone, $otp)
    {
        // TODO: Integrate real provider (MSG91 / Twilio)

        // For production safety, do not log OTP values.
        Log::info('Sending SMS OTP', [
            'phone' => $this->maskPhone((string) $phone),
            'channel' => 'sms',
        ]);

        return true;
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(0, $length - 4)) . substr($phone, -4);
    }
}