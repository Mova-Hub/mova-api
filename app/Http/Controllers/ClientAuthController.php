<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientFcmToken;
use App\Services\SmsService;
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
use Twilio\Rest\Client as TwilioClient;

class ClientAuthController extends Controller
{
    public function __construct(protected SmsService $smsService)
    {
    }

    /**
     * Helper: Sync FCM Token
     * Prevents duplicate tokens across different accounts on the same device.
     */
    private function syncFcmToken(Client $client, ?string $token, ?string $device): void
    {
        if (!$token) return;

        // 1. Remove this token if it belongs to ANY other user
        // (e.g. user switched accounts on same device)
        $type = str_starts_with($token, 'ExponentPushToken') ? 'expo' : 'fcm';

        // Remove from other users
        ClientFcmToken::where('fcm_token', $token)
            ->where('client_id', '!=', $client->id)
            ->delete();

        // 2. Save or update timestamp for this user
        $client->fcmTokens()->updateOrCreate(
            ['fcm_token' => $token],
            [
                'type' => $type,
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
            return response()->json(['status' => false, 'message' => 'Identifiants incorrects.'], 401);
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

    /**
     * UPDATE PASSWORD
     */
    public function updatePassword(Request $request): JsonResponse
    {
        // 1. Validate the incoming request
        $request->validate([
            'current_password' => 'required|string',
            // Enforcing the same strong password rules used in resetPassword
            'new_password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
        ]);

        /** @var Client $client */
        $client = $request->user();

        // 2. Check if the provided current password matches the one in the database
        if (! Hash::check($request->current_password, $client->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Le mot de passe actuel est incorrect.'
            ], 400);
        }

        // 3. Hash the new password and save it
        $client->forceFill([
            'password' => Hash::make($request->new_password)
        ])->save();

        // Optional: Revoke other tokens if you want to force sign-out on other devices
        // $client->tokens()->where('id', '!=', $client->currentAccessToken()->id)->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Votre mot de passe a été mis à jour avec succès.'
        ]);
    }

    /**
     * Toogle 2FA
     */
    public function toggle2FA(Request $request): JsonResponse
    {
        // 1. Validate the incoming boolean
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        /** @var Client $client */
        $client = $request->user();

        // 2. Update the user's 2FA status
        $client->forceFill([
            'is_2fa_enabled' => $request->enabled
        ])->save();

        // 3. Return a success response
        return response()->json([
            'status'  => true,
            'message' => $request->enabled
                ? 'L\'authentification à deux facteurs a été activée.'
                : 'L\'authentification à deux facteurs a été désactivée.',
            'data' => [
                'is_2fa_enabled' => $client->is_2fa_enabled
            ]
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

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        // Utilise ton helper privé existant
        $this->syncFcmToken($request->user(), $request->fcm_token, $request->device_name);

        return response()->json([
            'status' => true,
            'message' => 'FCM Token updated successfully.'
        ]);
    }

    /**
     * REQUEST PASSWORD RESET (OTP VERSION)
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string|exists:clients,phone']);

        $phone = $request->phone;
        $otp = rand(1000, 9999);

        // 1. Cache the OTP
        Cache::put('password_reset_' . $phone, $otp, 600);

        // 2. Attempt to send
        $smsSent = $this->smsService->sendOtp($phone, $otp);

        // 3. Handle failure safely
        if (!$smsSent && !app()->isLocal()) {
            Cache::forget('password_reset_' . $phone); // Clean up the cache

            return response()->json([
                'status'  => false,
                'message' => 'Impossible d\'envoyer le SMS. Veuillez vérifier que le numéro inclut l\'indicatif du pays (ex: +242...).'
            ], 500);
        }

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

    // Phone verification

    /**
     * REQUEST PHONE UPDATE (Send 6-digit OTP)
     */
    public function requestPhoneUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|unique:clients,phone',
        ]);

        $phone = $request->phone;
        $user = $request->user();

        $otp = rand(100000, 999999);

        // 1. Cache the data
        Cache::put('phone_update_' . $user->id, ['phone' => $phone, 'otp' => $otp], 600);

        // 2. Attempt to send
        $smsSent = $this->smsService->sendOtp($phone, $otp);

        // 3. Handle failure safely
        if (!$smsSent && !app()->isLocal()) {
            Cache::forget('phone_update_' . $user->id); // Clean up the cache

            return response()->json([
                'status' => false,
                'message' => 'Impossible d\'envoyer le SMS. Veuillez vérifier que le numéro inclut l\'indicatif du pays (ex: +242...).'
            ], 500);
        }

        Log::info("PHONE UPDATE OTP for {$phone}: {$otp}");

        return response()->json([
            'status'  => true,
            'message' => 'Code de vérification envoyé par SMS.',
            'debug_otp' => app()->isLocal() ? $otp : null
        ]);
    }

    /**
     * VERIFY PHONE UPDATE (Check OTP & Save)
     */
    public function verifyPhoneUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => 'required|numeric',
        ]);

        $user = $request->user();
        $cachedData = Cache::get('phone_update_' . $user->id);

        // 1. Verify OTP
        if (!$cachedData || (int)$cachedData['otp'] !== (int)$request->otp) {
            return response()->json([
                'status'  => false,
                'message' => 'Code invalide ou expiré.'
            ], 400);
        }

        // 2. Update the phone number
        $user->forceFill([
            'phone' => $cachedData['phone']
        ])->save();

        // 3. Clear Cache
        Cache::forget('phone_update_' . $user->id);

        return response()->json([
            'status'  => true,
            'message' => 'Votre numéro de téléphone a été mis à jour avec succès.',
            'data'    => new ClientResource($user)
        ]);
    }

    /**
     * DELETE ACCOUNT
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        // 1. Delete avatar from storage if it exists to free up space
        if ($client->avatar && Storage::disk('public')->exists($client->avatar)) {
            Storage::disk('public')->delete($client->avatar);
        }

        // 2. Revoke all Sanctum access tokens
        $client->tokens()->delete();

        // 3. Remove any linked FCM tokens
        $client->fcmTokens()->delete();

        // 4. Delete the user record from the database
        // Note: If you use SoftDeletes on the Client model, this will just set deleted_at.
        // Otherwise, it permanently removes the row.
        $client->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Votre compte et vos données ont été supprimés avec succès.'
        ]);
    }
}
