<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\UserFcmToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registering a field phone for push.
 *
 * The staff mirror of `ClientAuthController@updateFcmToken`. Without it a
 * coordinator handed a convoy can only be told by e-mail, and nobody reads
 * e-mail on a bus at six in the morning.
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token'   => 'required|string|max:255',
            'type'        => ['nullable', Rule::in(['fcm', 'expo'])],
            'device_name' => 'nullable|string|max:120',
        ]);

        /*
         * Keyed on the TOKEN, not on the user.
         *
         * `user_fcm_tokens.fcm_token` is globally unique, so a phone handed from
         * one inspector to the next re-registers the same token and it MOVES to
         * the new owner. Keying on the user instead would leave the previous
         * holder receiving the new one's missions — and `create()` alone would
         * just fail the unique constraint on every app launch.
         */
        UserFcmToken::updateOrCreate(
            ['fcm_token' => $data['fcm_token']],
            [
                'user_id'      => $request->user()->id,
                'type'         => $data['type'] ?? 'fcm',
                'device_name'  => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['status' => true, 'message' => 'Appareil enregistré.']);
    }

    /**
     * Sign-out, properly.
     *
     * A phone that logs out must stop receiving missions. Without this the
     * token survives and the next person's assignments arrive on the previous
     * inspector's handset.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => 'required|string|max:255']);

        UserFcmToken::where('fcm_token', $data['fcm_token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['status' => true]);
    }
}
