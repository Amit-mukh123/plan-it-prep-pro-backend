<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSession;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Services\BrevoMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    // ==============================
    // 1. SEND OTP
    // ==============================
    public function sendOtp(Request $request, OtpService $otpService, SmsService $smsService, BrevoMailService $brevoMailService)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('phone_number', $request->phone_number)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'msg' => 'Account not found.'
                ], 404);
            }

            // Generate OTP
            $otp = $otpService->generateOtp($user, 'sms');

            // Send SMS
            $smsService->send($user->phone_number, $otp);

            // Send Email (optional) via Brevo
            if ($user->email) {
                $brevoMailService->sendOtp($user->email, $otp);
            }

            return response()->json([
                'status' => true,
                'msg' => 'OTP sent successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('OTP Send Error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'msg' => 'Server Error. Please try again later.'
            ], 500);
        }
    }

    // ==============================
    // SEND EMAIL OTP (Passwordless Email Login)
    // ==============================
    public function sendEmailOtp(Request $request, OtpService $otpService, BrevoMailService $brevoMailService)
    {
        Log::info('Email OTP send request initiated', [
            'email' => $request->email ?? 'unknown',
            'ip_address' => $request->ip(),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            Log::warning('Email OTP validation failed', [
                'email' => $request->email ?? 'unknown',
                'errors' => $validator->errors()->toArray(),
            ]);
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning('Email OTP user not found', [
                    'email' => $request->email,
                    'ip_address' => $request->ip(),
                ]);
                return response()->json(['status' => false, 'msg' => 'Account not found.'], 404);
            }

            $otp = $otpService->generateOtp($user, 'email');

            $mailResult = $brevoMailService->sendOtp($user->email, $otp);

            Log::info('Email OTP sent successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mail_success' => $mailResult['success'],
                'mail_status' => $mailResult['status'],
            ]);

            return response()->json(['status' => true, 'msg' => 'OTP sent to email'], 200);
        } catch (\Exception $e) {
            Log::error('Email OTP Send Error', [
                'email' => $request->email ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => false, 'msg' => 'Server Error'], 500);
        }
    }

    // ==============================
    // VERIFY EMAIL OTP
    // ==============================
    public function verifyEmailOtp(Request $request, OtpService $otpService)
    {
        Log::info('Email OTP verification request initiated', [
            'email' => $request->email ?? 'unknown',
            'ip_address' => $request->ip(),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string'
        ]);

        if ($validator->fails()) {
            Log::warning('Email OTP verification validation failed', [
                'email' => $request->email ?? 'unknown',
                'errors' => $validator->errors()->toArray(),
            ]);
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning('Email OTP verification user not found', [
                    'email' => $request->email,
                    'ip_address' => $request->ip(),
                ]);
                return response()->json(['status' => false, 'msg' => 'User not found'], 404);
            }

            $result = $otpService->verifyOtp($user, $request->otp);

            if (!$result['status']) {
                Log::warning('Email OTP verification failed', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'reason' => $result['msg'],
                    'ip_address' => $request->ip(),
                ]);
                return response()->json(['status' => false, 'msg' => $result['msg']], 401);
            }

            $user->update([
                'is_verified' => true,
                'last_login_at' => now()
            ]);

            Log::info('Email OTP verification succeeded', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $request->ip(),
            ]);

            return $this->generateSession($user, $request);
        } catch (\Exception $e) {
            Log::error('Email OTP Verify Error', [
                'email' => $request->email ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => false, 'msg' => 'Server Error'], 500);
        }
    }

    // ==============================
    // 2. VERIFY OTP
    // ==============================
    public function verifyOtp(Request $request, OtpService $otpService)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'otp' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('phone_number', $request->phone_number)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'msg' => 'User not found'
                ], 404);
            }

            $result = $otpService->verifyOtp($user, $request->otp);

            if (!$result['status']) {
                return response()->json([
                    'status' => false,
                    'msg' => $result['msg']
                ], 401);
            }

            // Update user login info
            $user->update([
                'is_verified' => true,
                'last_login_at' => now()
            ]);

            // Generate session (your existing method)
            return $this->generateSession($user, $request);

        } catch (\Exception $e) {
            Log::error('OTP Verify Error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'msg' => 'Server Error'
            ], 500);
        }
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
    // 6. ME (Authenticated User Details)
    // ==============================
    public function me(Request $request)
    {
        return response()->json([
            'status' => true,
            'user'   => $request->user()
        ], 200);
    }

    // ==============================
    // SESSION GENERATION
    // ==============================
    private function generateSession($user, $request)
    {
        try {
            $response = DB::transaction(function () use ($user, $request) {
                $devicePlatform = $request->header('X-Device-Platform') 
                    ?? $request->input('device_platform');
                $deviceName = $request->header('X-Device-Name') 
                    ?? $request->input('device_name');

                $refreshToken = hash('sha256', Str::random(60));

                $session = UserSession::create([
                    'user_id'         => $user->id,
                    'refresh_token'   => $refreshToken,
                    'device_platform' => $devicePlatform,
                    'device_name'     => $deviceName,
                    'ip_address'      => $request->ip(),
                    'user_agent'      => $request->userAgent(),
                    'expires_at'      => now()->addDays(30),
                    'last_used_at'    => now(),
                    'is_active'       => true
                ]);

                // Bind Sanctum access token directly to the specific UserSession ID
                $accessToken = $user->createToken('session:' . $session->id)->plainTextToken;

                return response()->json([
                    'status'        => true,
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'session_id'    => $session->id,
                    'user'          => [
                        'id'           => $user->id,
                        'phone_number' => $user->phone_number,
                        'email'        => $user->email,
                    ]
                ], 200);
            });

            // Keep login-attempt logging outside DB transaction.
            $this->logAttempt($request, $user->id, true, null);

            return $response;
        } catch (\Exception $e) {
            Log::error('Session Generation Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => false, 'msg' => 'Token generation failed'], 500);
        }
    }

    // ==============================
    // REFRESH TOKEN (Production Grade Token Rotation)
    // ==============================
    public function refreshToken(Request $request)
    {
        $refreshTokenStr = $request->input('refresh_token') ?? $request->header('X-Refresh-Token');

        if (!$refreshTokenStr) {
            return response()->json(['status' => false, 'msg' => 'Refresh token is required'], 422);
        }

        $session = UserSession::where('refresh_token', $refreshTokenStr)->first();

        if (!$session) {
            return response()->json(['status' => false, 'msg' => 'Invalid session or refresh token'], 401);
        }

        // Security check: Token Reuse Detection
        if (!$session->is_active || !is_null($session->revoked_at)) {
            Log::warning('Refresh token reuse attempt detected', [
                'session_id' => $session->id,
                'user_id'    => $session->user_id,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            // Revoke all active sessions for user to protect account against compromised token reuse
            UserSession::where('user_id', $session->user_id)
                ->where('is_active', true)
                ->update([
                    'is_active'  => false,
                    'revoked_at' => now(),
                ]);

            return response()->json(['status' => false, 'msg' => 'Session has been revoked due to suspicious activity'], 401);
        }

        // Expiration check
        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->update([
                'is_active'  => false,
                'revoked_at' => now(),
            ]);

            return response()->json(['status' => false, 'msg' => 'Session has expired'], 401);
        }

        $user = User::find($session->user_id);

        if (!$user || (isset($user->is_active) && !$user->is_active)) {
            return response()->json(['status' => false, 'msg' => 'User account is disabled or no longer exists'], 401);
        }

        // Token Rotation: Deactivate current session token
        $session->update([
            'is_active'    => false,
            'revoked_at'   => now(),
            'last_used_at' => now(),
        ]);

        return $this->generateSession($user, $request);
    }

    // ==============================
    // LOGOUT (Device / Session Deactivation)
    // ==============================
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['status' => false, 'msg' => 'Unauthenticated'], 401);
            }

            $currentToken = $user->currentAccessToken();
            $linkedSessionId = null;

            // Extract linked session ID from token name if available
            if ($currentToken && Str::startsWith($currentToken->name, 'session:')) {
                $linkedSessionId = Str::after($currentToken->name, 'session:');
            }

            // Revoke current Sanctum access token
            if ($currentToken) {
                $currentToken->delete();
            }

            $refreshTokenStr = $request->input('refresh_token') ?? $request->header('X-Refresh-Token');
            $logoutAll = $request->boolean('logout_all') || $request->boolean('all_devices');

            if ($logoutAll) {
                // Revoke all Sanctum access tokens
                $user->tokens()->delete();

                // Deactivate all active sessions for user
                UserSession::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active'  => false,
                        'revoked_at' => now(),
                    ]);

                return response()->json([
                    'status' => true,
                    'msg'    => 'Successfully logged out from all devices'
                ], 200);
            }

            // Deactivate specific refresh token session
            if ($refreshTokenStr) {
                UserSession::where('user_id', $user->id)
                    ->where('refresh_token', $refreshTokenStr)
                    ->update([
                        'is_active'  => false,
                        'revoked_at' => now(),
                    ]);
            } elseif ($linkedSessionId) {
                // Automatically deactivate the session linked directly to this Bearer token
                UserSession::where('user_id', $user->id)
                    ->where('id', $linkedSessionId)
                    ->update([
                        'is_active'  => false,
                        'revoked_at' => now(),
                    ]);
            } else {
                // Fallback: deactivate active session matching IP/User-Agent or latest active session
                $sessionToRevoke = UserSession::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->where('ip_address', $request->ip())
                    ->latest()
                    ->first()
                    ?? UserSession::where('user_id', $user->id)
                        ->where('is_active', true)
                        ->latest()
                        ->first();

                if ($sessionToRevoke) {
                    $sessionToRevoke->update([
                        'is_active'  => false,
                        'revoked_at' => now(),
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'msg'    => 'Successfully logged out'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Logout Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'msg'    => 'Logout failed'
            ], 500);
        }
    }

    // ==============================
    // LOGOUT ALL DEVICES
    // ==============================
    public function logoutAll(Request $request)
    {
        $request->merge(['logout_all' => true]);
        return $this->logout($request);
    }

    // ==============================
    // GET ACTIVE SESSIONS
    // ==============================
    public function getActiveSessions(Request $request)
    {
        try {
            $user = $request->user();
            $sessions = UserSession::where('user_id', $user->id)
                ->active()
                ->orderBy('created_at', 'desc')
                ->get(['id', 'device_platform', 'device_name', 'ip_address', 'user_agent', 'last_used_at', 'created_at']);

            return response()->json([
                'status'   => true,
                'sessions' => $sessions
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get Active Sessions Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'msg' => 'Failed to fetch sessions'], 500);
        }
    }

    // ==============================
    // REVOKE SPECIFIC SESSION
    // ==============================
    public function revokeSession(Request $request, $sessionId)
    {
        try {
            $user = $request->user();
            $session = UserSession::where('user_id', $user->id)
                ->where('id', $sessionId)
                ->first();

            if (!$session) {
                return response()->json(['status' => false, 'msg' => 'Session not found'], 404);
            }

            $session->update([
                'is_active'  => false,
                'revoked_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'msg'    => 'Session revoked successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Revoke Session Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'msg' => 'Failed to revoke session'], 500);
        }
    }

    // ==============================
    // LOG ATTEMPTS
    // ==============================
    private function logAttempt($request, $userId, $success, $reason)
    {
        try {
            $identifier = $request->phone_number ?? $request->email ?? 'unknown';

            // login_attempts.phone_number appears to be varchar(20) in DB.
            // Truncate to avoid QueryException and keep auth flow stable.
            if (is_string($identifier) && strlen($identifier) > 20) {
                $identifier = substr($identifier, 0, 20);
            }

            LoginAttempt::create([
                'user_id'        => $userId,
                'phone_number'   => $identifier,
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