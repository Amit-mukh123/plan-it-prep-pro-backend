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

class AuthController extends Controller
{

    // ==============================
    // 1. SEND OTP (PHONE LOGIN)
    // ==============================
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>false,'msg'=>$validator->errors()]);
        }

        $phone = $request->phone_number;

        $user = User::firstOrCreate([
            'phone_number' => $phone
        ]);

         \Log::info('User ID:', ['id' => $user->id, 'type' => gettype($user->id)]);

        // delete old otp
        OtpVerification::where('user_id',$user->id)->delete();
       
        $otp = 123456; // static for now

        OtpVerification::create([
            'user_id'=>$user->id,
            'otp_code'=>$otp,
            'expires_at'=>Carbon::now()->addMinutes(5)
        ]);

        return response()->json([
            'status'=>true,
            'msg'=>'OTP sent',
            'otp'=>$otp // remove in production
        ]);
    }


    // ==============================
    // 2. VERIFY OTP
    // ==============================
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number'=>'required',
            'otp'=>'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>false,'msg'=>$validator->errors()]);
        }

        $user = User::where('phone_number',$request->phone_number)->first();

        if (!$user) {
            return response()->json(['status'=>false,'msg'=>'User not found']);
        }

        $otp = OtpVerification::where('user_id',$user->id)
            ->where('otp_code',$request->otp)
            //->where('is_used',false)
            ->first();

        if (!$otp) {
            return response()->json(['status'=>false,'msg'=>'Invalid OTP']);
        }

        if ($otp->expires_at < now()) {
            return response()->json(['status'=>false,'msg'=>'OTP expired']);
        }

        $otp->update(['is_used'=>true]);

        $user->update([
            'is_verified'=>true,
            'last_login_at'=>now()
        ]);

        return $this->generateSession($user,$request);
    }


    // ==============================
    // 3. EMAIL REGISTER
    // ==============================
    public function registerEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'=>'required|email|unique:users',
            'password'=>'required|min:6',
            'mobile' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>false,'msg'=>$validator->errors()]);
        }

        $user = User::create([
            'email'=>$request->email,
            'password'=>Hash::make($request->password),
            'phone_number' => $request->mobile
        ]);

        return response()->json([
            'status'=>true,
            'msg'=>'Registered successfully'
        ]);
    }


    // ==============================
    // 4. EMAIL LOGIN
    // ==============================
    public function loginEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'=>'required',
            'password'=>'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>false,'msg'=>$validator->errors()]);
        }

        $user = User::where('email',$request->email)->first();

        if (!$user || !Hash::check($request->password,$user->password)) {

            $this->logAttempt($request,null,false,'wrong_password');

            return response()->json([
                'status'=>false,
                'msg'=>'Invalid credentials'
            ]);
        }

        return $this->generateSession($user,$request);
    }


    // ==============================
    // 5. GOOGLE LOGIN (FUTURE READY)
    // ==============================
    public function googleLogin(Request $request)
    {
        $email = $request->email;

        $user = User::firstOrCreate([
            'email'=>$email
        ]);

        return $this->generateSession($user,$request);
    }


    // ==============================
    // SESSION GENERATION
    // ==============================
    private function generateSession($user,$request)
    {
        $accessToken = $user->createToken('access_token')->plainTextToken;

        $refreshToken = hash('sha256', Str::random(60));

        UserSession::create([
            'user_id'=>$user->id,
            'refresh_token'=>$refreshToken,
            'ip_address'=>$request->ip(),
            'user_agent'=>$request->userAgent(),
            'expires_at'=>now()->addDays(30)
        ]);

        $this->logAttempt($request,$user->id,true,null);

        return response()->json([
            'status'=>true,
            'access_token'=>$accessToken,
            'refresh_token'=>$refreshToken,
            'user'=>$user
        ]);
    }


    // ==============================
    // REFRESH TOKEN
    // ==============================
    public function refreshToken(Request $request)
    {
        $session = UserSession::where('refresh_token',$request->refresh_token)
            ->where('is_active',true)
            ->first();

        if (!$session) {
            return response()->json(['status'=>false,'msg'=>'Invalid token']);
        }

        $user = User::find($session->user_id);

        return $this->generateSession($user,$request);
    }


    // ==============================
    // LOG ATTEMPTS
    // ==============================
    private function logAttempt($request,$userId,$success,$reason)
    {
        LoginAttempt::create([
            'user_id'=>$userId,
            'phone_number'=>$request->phone_number ?? '',
            'ip_address'=>$request->ip(),
            'user_agent'=>$request->userAgent(),
            'is_success'=>$success,
            'failure_reason'=>$reason
        ]);
    }

}