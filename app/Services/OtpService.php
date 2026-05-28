<?php

namespace App\Services;

use App\Models\OtpVerification;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function generateOtp($user, $channel = 'sms')
    {
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

        return $otp; // return plain OTP for sending
    }

    public function verifyOtp($user, $otpInput)
    {
        $otpRecord = OtpVerification::where('user_id', $user->id)
            ->where('is_used', false)
            ->latest()
            ->first();

        if (!$otpRecord) {
            return ['status' => false, 'msg' => 'OTP not found'];
        }

        // Check expiry
        if (now()->gt($otpRecord->expires_at)) {
            return ['status' => false, 'msg' => 'OTP expired'];
        }

        // Check attempts
        if ($otpRecord->attempt_count >= 5) {
            return ['status' => false, 'msg' => 'Too many attempts'];
        }

        // Verify hash
        if (!Hash::check($otpInput, $otpRecord->otp_code)) {
            $otpRecord->increment('attempt_count');
            return ['status' => false, 'msg' => 'Invalid OTP'];
        }

        // Mark used
        $otpRecord->update(['is_used' => true]);

        return ['status' => true];
    }
}