<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    /**
     * List clients.
     *
     * The search used to be `where(name)->orWhere(phone)`, ungrouped — so the
     * `orWhere` escaped any constraint added before it. There is none today,
     * but the moment a filter is added above (status, date range) the search
     * would silently discard it and return the whole table. Grouped now.
     */
    public function index(Request $request)
    {
        $query = Client::withCount('orders')->latest();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $status === 'blocked'
                ? $query->whereNotNull('blocked_at')
                : $query->whereNull('blocked_at');
        }

        return ClientResource::collection($query->paginate((int) $request->input('per_page', 20)));
    }

    public function show(int|string $id)
    {
        $client = Client::withCount('orders')->findOrFail($id);

        return new ClientResource($client);
    }

    /**
     * Correct a client's contact details.
     *
     * `console/src/api/client.ts` has always called `PUT /clients/{id}`, which
     * did not exist — the controller was read-only, so every edit from the
     * back-office 404'd.
     *
     * Contact fields only. A client's password, phone verification state and
     * social provider bindings are theirs; staff correcting a misspelt name
     * must not be able to reach an account takeover through the same endpoint.
     * Changing `phone` here does NOT mark it verified — see the migration note
     * on `phone_verified_at`.
     */
    public function update(Request $request, int|string $id)
    {
        $client = Client::findOrFail($id);

        $data = $request->validate([
            'name'  => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('clients', 'email')->ignore($client->id)],
            // E.164, matching ClientAuthController. Unique, because the phone is
            // the login identifier — two clients sharing one locks somebody out.
            'phone' => ['sometimes', 'required', 'string', 'regex:/^\+[1-9]\d{7,14}$/', Rule::unique('clients', 'phone')->ignore($client->id)],
        ], [
            'phone.regex' => 'Numéro invalide. Format attendu : +242061234567.',
        ]);

        if (array_key_exists('phone', $data) && $data['phone'] !== $client->phone) {
            // A staff-side correction is not a verification event.
            $client->phone_verified_at = null;
        }

        $client->fill($data)->save();

        return new ClientResource($client->fresh()->loadCount('orders'));
    }

    /**
     * Blocks a client.
     *
     * Revokes every token, so the block takes effect on the next request rather
     * than whenever their session happens to expire. Reversible, unlike a Pass
     * card block — a customer account is suspended pending a dispute, not
     * destroyed, and their order history has to survive for accounting.
     */
    public function block(Request $request, int|string $id)
    {
        $client = Client::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $client->forceFill([
            'blocked_at' => now(),
            'blocked_reason' => $data['reason'],
        ])->save();

        $client->tokens()->delete();

        return new ClientResource($client->fresh()->loadCount('orders'));
    }

    public function unblock(int|string $id)
    {
        $client = Client::findOrFail($id);

        $client->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();

        return new ClientResource($client->fresh()->loadCount('orders'));
    }
}
