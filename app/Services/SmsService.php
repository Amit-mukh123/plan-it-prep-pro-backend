<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send($phone, $otp)
    {
        // TODO: Integrate real provider (MSG91 / Twilio)

        // For now just log (safe for dev)
        Log::info("Sending SMS OTP to {$phone}: {$otp}");

        return true;
    }
}