<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientFcmToken;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ClientAuthController extends Controller
{
    /**
     * Helper: Sync FCM Token
     * Prevents duplicate tokens across different accounts on the same device.
     */
    private function syncFcmToken(Client $client, ?string $token, ?string $device): void
    {
        if (!$token) return;

        // 1. Remove this token if it belongs to ANY other user
        // (e.g. user switched accounts on same device)
        ClientFcmToken::where('fcm_token', $token)
            ->where('client_id', '!=', $client->id)
            ->delete();

        // 2. Save or update timestamp for this user
        $client->fcmTokens()->updateOrCreate(
            ['fcm_token' => $token],
            [
                'device_name' => $device ?? 'mobile',
                'last_used_at' => now()
            ]
        );
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
            $client = Client::create([
                'name'     => $data['name'],
                'phone'    => $data['phone'],
                'email'    => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
            ]);

            event(new Registered($client));

            // --- FCM LOGIC ---
            $this->syncFcmToken($client, $data['fcm_token'] ?? null, $data['device_name'] ?? null);

            $token = $client->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

            return response()->json([
                'status'  => true,
                'message' => 'Account created successfully.',
                'data'    => [
                    'user'  => new ClientResource($client),
                    'token' => $token,
                ]
            ], 201);
        });
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $client = Client::where('phone', $data['phone'])->first();

        if (! $client || ! Hash::check($data['password'], $client->password)) {
            return response()->json(['status' => false, 'message' => 'Invalid credentials.'], 401);
        }

        $client->forceFill(['last_login_at' => now()])->save();

        // --- FCM LOGIC ---
        // Ensure LoginRequest validates 'fcm_token' as nullable|string
        $this->syncFcmToken($client, $request->fcm_token, $request->device_name);

        $token = $client->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'status'  => true,
            'message' => 'Logged in successfully.',
            'data'    => [
                'user'  => new ClientResource($client),
                'token' => $token,
            ]
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        // 1. Validation
        $request->validate([
            'name'   => 'required|string|max:80',
            'phone'  => 'nullable|string|max:13',
            'email'  => 'nullable|email|max:255|unique:clients,email,' . $request->user()->id,
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096', // Max 4MB
        ]);

        $client = $request->user();

        // 2. Handle Text Updates
        $client->name = $request->name;
        $client->email = $request->email;

        // 3. Handle Avatar Upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists (and isn't a default one)
            if ($client->avatar_path && Storage::disk('public')->exists($client->avatar_path)) {
                Storage::disk('public')->delete($client->avatar_path);
            }

            // Store new avatar (store in 'avatars' folder on public disk)
            $path = $request->file('avatar')->store('avatars', 'public');
            $client->avatar = $path; // Save the path relative to storage/app/public
        }

        $client->save();

        return response()->json([
            'status' => true,
            'message' => 'Profil mis à jour.',
            'data' => new ClientResource($client),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user(); // This returns a Client instance due to auth:sanctum

        // 1. Revoke Sanctum Token
        $user->currentAccessToken()->delete();

        // 2. Remove FCM Token
        if ($request->filled('fcm_token')) {
            $user->fcmTokens()->where('fcm_token', $request->fcm_token)->delete();
        }

        return response()->json([
            'status'  => true,
            'message' => 'Logged out successfully.'
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user(); // auth:sanctum → Client guard

        return response()->json([
            'status' => true,
            'data'   => new ClientResource($client),
        ]);
    }

    /**
     * REQUEST PASSWORD RESET (OTP VERSION)
     * Replaces the email-based logic since we are using Phone.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string|exists:clients,phone']);

        $phone = $request->phone;

        // Generate 4-digit OTP
        $otp = rand(1000, 9999);

        // Store in Cache for 10 minutes
        Cache::put('password_reset_' . $phone, $otp, 600);

        // TODO: Send SMS via Gateway (Twilio/Infobip/etc)
        // For development, we return it in debug_otp
        Log::info("PASSWORD RESET OTP for {$phone}: {$otp}");

        return response()->json([
            'status'  => true,
            'message' => 'OTP sent to your phone.',
            'debug_otp' => app()->isLocal() ? $otp : null
        ]);
    }

    /**
     * RESET PASSWORD (OTP VERSION)
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => 'required|string|exists:clients,phone',
            'otp'      => 'required|numeric',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Verify OTP
        $cachedOtp = Cache::get('password_reset_' . $request->phone);

        if (! $cachedOtp || (int)$cachedOtp !== (int)$request->otp) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid or expired OTP.'
            ], 400);
        }

        // Reset Password
        $client = Client::where('phone', $request->phone)->first();

        $client->forceFill([
            'password' => Hash::make($request->password)
        ])->setRememberToken(Str::random(60));

        $client->save();

        // Clear OTP
        Cache::forget('password_reset_' . $request->phone);

        return response()->json([
            'status'  => true,
            'message' => 'Password has been reset successfully.'
        ]);
    }
}
