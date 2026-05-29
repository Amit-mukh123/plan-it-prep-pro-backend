<?php

namespace App\Services;

use App\Models\OtpVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpService
{
    public function generateOtp($user, $channel = 'sms')
    {
        Log::info('OTP generation started', [
            'user_id' => $user->id,
            'channel' => $channel,
        ]);

        // Delete old unused OTP
        OtpVerification::where('user_id', $user->id)
            ->where('is_used', false)
            ->delete();

        $otp = random_int(100000, 999999);

        OtpVerification::create([
            'user_id' => $user->id,
            'otp_code' => Hash::make($otp),
            'channel' => $channel,
            'expires_at' => now()->addMinutes(5),
            'is_used' => false,
            'attempt_count' => 0
        ]);

        Log::info('OTP generated', [
            'user_id' => $user->id,
            'channel' => $channel,
        ]);

        return $otp; // return plain OTP for sending
    }

    public function verifyOtp($user, $otpInput)
    {
        Log::info('OTP verification started', [
            'user_id' => $user->id,
        ]);

        $otpRecord = OtpVerification::where('user_id', $user->id)
            ->where('is_used', false)
            ->latest()
            ->first();

        if (!$otpRecord) {
            Log::warning('OTP verification failed: record not found', [
                'user_id' => $user->id,
            ]);

            return ['status' => false, 'msg' => 'OTP not found'];
        }

        // Check expiry
        if (now()->gt($otpRecord->expires_at)) {
            Log::warning('OTP verification failed: expired', [
                'user_id' => $user->id,
            ]);

            return ['status' => false, 'msg' => 'OTP expired'];
        }

        // Check attempts
        if ($otpRecord->attempt_count >= 5) {
            Log::warning('OTP verification failed: too many attempts', [
                'user_id' => $user->id,
            ]);

            return ['status' => false, 'msg' => 'Too many attempts'];
        }

        // Verify hash
        if (!Hash::check($otpInput, $otpRecord->otp_code)) {
            $otpRecord->increment('attempt_count');

            Log::warning('OTP verification failed: invalid code', [
                'user_id' => $user->id,
            ]);

            return ['status' => false, 'msg' => 'Invalid OTP'];
        }

        // Mark used
        $otpRecord->update(['is_used' => true]);

        Log::info('OTP verification succeeded', [
            'user_id' => $user->id,
        ]);

        return ['status' => true];
    }
}