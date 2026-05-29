<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoMailService
{
    public function sendOtp(string $toEmail, string $otp): array
    {
        Log::info('Brevo OTP email send initiated', [
            'to_email' => $toEmail,
            'timestamp' => now()->toIso8601String(),
        ]);

        $response = Http::withHeaders([
            'api-key' => config('services.brevo.key'),
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'email' => config('services.brevo.sender_email'),
                'name' => config('services.brevo.sender_name'),
            ],
            'to' => [
                ['email' => $toEmail]
            ],
            'subject' => 'Your OTP Code',
            'htmlContent' => "
                <div style='font-family:sans-serif'>
                    <h2>Your OTP Code</h2>
                    <p>Your verification code is:</p>
                    <h1 style='letter-spacing:5px;'>$otp</h1>
                    <p>This OTP will expire in 5 minutes.</p>
                </div>
            ",
        ]);

        if ($response->successful()) {
            Log::info('Brevo OTP email sent successfully', [
                'to_email' => $toEmail,
                'status' => $response->status(),
                'message_id' => $response->json()['id'] ?? null,
                'timestamp' => now()->toIso8601String(),
            ]);
        } else {
            Log::error('Brevo OTP email send failed', [
                'to_email' => $toEmail,
                'status' => $response->status(),
                'response_body' => $response->json(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return [
            'success' => $response->successful(),
            'data' => $response->json(),
            'status' => $response->status(),
        ];
    }
}