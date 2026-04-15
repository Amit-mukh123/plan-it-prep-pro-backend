<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OtpVerification;
use App\Models\UserSession;
use App\Models\LoginAttempt;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    // ==============================
    // 1. SEND OTP (PHONE LOGIN)
    // ==============================
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::where('phone_number', $request->phone_number)->first();

            if (!$user) {
                return response()->json(['status' => false, 'msg' => 'User Not Registered'], 404);
            }

            DB::transaction(function () use ($user) {
                // Delete old OTPs for this user
                OtpVerification::where('user_id', $user->id)->delete();

                $otp = 123456; // TODO: Replace with dynamic generator in production

                OtpVerification::create([
                    'user_id'    => $user->id,
                    'otp_code'   => $otp,
                    'expires_at' => Carbon::now()->addMinutes(5)
                ]);
            });

            return response()->json([
                'status' => true,
                'msg'    => 'OTP sent successfully',
                'otp'    => 123456 // TODO: Remove this line in production
            ], 200);

        } catch (\Exception $e) {
            Log::error('OTP Send Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'msg' => 'Server Error. Please try again later.'], 500);
        }
    }

    // ==============================
    // 2. VERIFY OTP
    // ==============================
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required',
            'otp'          => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::where('phone_number', $request->phone_number)->first();

        if (!$user) {
            return response()->json(['status' => false, 'msg' => 'User not found'], 404);
        }

        $otpRecord = OtpVerification::where('user_id', $user->id)
            ->where('otp_code', $request->otp)
            ->first();

        if (!$otpRecord) {
            $this->logAttempt($request, $user->id, false, 'invalid_otp');
            return response()->json(['status' => false, 'msg' => 'Invalid OTP'], 401);
        }

        if (Carbon::parse($otpRecord->expires_at)->isPast()) {
            return response()->json(['status' => false, 'msg' => 'OTP expired'], 401);
        }

        return DB::transaction(function () use ($user, $otpRecord, $request) {
            $otpRecord->update(['is_used' => true]);
            $user->update([
                'is_verified'   => true,
                'last_login_at' => now()
            ]);

            return $this->generateSession($user, $request);
        });
    }

    // ==============================
    // 3. EMAIL REGISTER
    // ==============================
    public function registerEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|min:6',
            'mobile'   => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        // Check for existing user by Email OR Mobile in one go
        $existingUser = User::where('email', $request->email)
            ->orWhere('phone_number', $request->mobile)
            ->first();

        if ($existingUser) {
            $conflict = ($existingUser->email === $request->email) ? 'Email' : 'Phone number';
            return response()->json([
                'status'  => false,
                'message' => "$conflict is already registered"
            ], 409);
        }

        try {
            $user = User::create([
                'email'        => $request->email,
                'password'     => Hash::make($request->password),
                'phone_number' => $request->mobile
            ]);

            return response()->json([
                'status' => true,
                'msg'    => 'Registered successfully'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registration Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'msg' => 'Registration failed'], 500);
        }
    }

    // ==============================
    // 4. EMAIL LOGIN
    // ==============================
    public function loginEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->logAttempt($request, $user->id ?? null, false, 'wrong_credentials');
            return response()->json([
                'status' => false,
                'msg'    => 'Invalid credentials'
            ], 401);
        }

        return $this->generateSession($user, $request);
    }

    // ==============================
    // 5. GOOGLE LOGIN
    // ==============================
    public function googleLogin(Request $request)
    {
        if (!$request->email) {
            return response()->json(['status' => false, 'msg' => 'Email is required'], 400);
        }

        try {
            $user = User::firstOrCreate(
                ['email' => $request->email],
                ['password' => Hash::make(Str::random(16))] // Set dummy password for new users
            );

            return $this->generateSession($user, $request);
        } catch (\Exception $e) {
            Log::error('Google Login Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'msg' => 'Auth failed'], 500);
        }
    }

    // ==============================
    // SESSION GENERATION
    // ==============================
    private function generateSession($user, $request)
    {
        try {
            return DB::transaction(function () use ($user, $request) {
                // Deactivate old sessions if your logic requires single-device login
                // UserSession::where('user_id', $user->id)->update(['is_active' => false]);

                $accessToken = $user->createToken('access_token')->plainTextToken;
                $refreshToken = hash('sha256', Str::random(60));

                UserSession::create([
                    'user_id'       => $user->id,
                    'refresh_token' => $refreshToken,
                    'ip_address'    => $request->ip(),
                    'user_agent'    => $request->userAgent(),
                    'expires_at'    => now()->addDays(30),
                    'is_active'     => true
                ]);

                $this->logAttempt($request, $user->id, true, null);

                return response()->json([
                    'status'        => true,
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'user'          => $user
                ], 200);
            });
        } catch (\Exception $e) {
            Log::error('Session Generation Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'msg' => 'Token generation failed'], 500);
        }
    }

    // ==============================
    // REFRESH TOKEN
    // ==============================
    public function refreshToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'msg' => 'Refresh token required'], 422);
        }

        $session = UserSession::where('refresh_token', $request->refresh_token)
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return response()->json(['status' => false, 'msg' => 'Invalid or expired session'], 401);
        }

        $user = User::find($session->user_id);
        
        if (!$user) {
            return response()->json(['status' => false, 'msg' => 'User no longer exists'], 404);
        }

        // Revoke the old session used to refresh
        $session->update(['is_active' => false]);

        return $this->generateSession($user, $request);
    }

    // ==============================
    // LOG ATTEMPTS
    // ==============================
    private function logAttempt($request, $userId, $success, $reason)
    {
        try {
            LoginAttempt::create([
                'user_id'        => $userId,
                'phone_number'   => $request->phone_number ?? $request->email ?? 'unknown',
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'is_success'     => $success,
                'failure_reason' => $reason
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log login attempt: ' . $e->getMessage());
        }
    }
}