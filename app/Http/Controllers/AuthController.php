<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required','string','max:80'],
            'email'    => ['required','email','max:255','unique:users,email'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email'=> $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // optional: abilities e.g. ['*'] or ['read','write']
        $token = $user->createToken('api-token', ['*'])->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token, // client stores as Bearer
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required','email','max:255'],
            'password' => ['required','string'],
            'device'   => ['nullable','string','max:60'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 422);
        }

        // revoke old tokens for this device name if you want single-session per device
        $deviceName = $credentials['device'] ?? 'web';
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, ['*'])->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        // revoke current token
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté avec succès.']);
    }
}
