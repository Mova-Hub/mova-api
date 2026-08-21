<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved addresses — the Domicile / Travail / École shortcuts, plus any custom
 * places the client adds.
 *
 * Every query is scoped through `$request->user()`, never by an id from the
 * request body, so one client can never read or modify another's addresses.
 */
class SavedAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->savedAddresses()
            // Fixed shortcuts first and in a stable order, so the list doesn't
            // reshuffle as the user adds custom entries.
            ->orderByRaw("FIELD(kind, 'home', 'work', 'school', 'custom')")
            ->orderBy('created_at')
            ->get();

        return response()->json(['status' => true, 'data' => $addresses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        /**
         * A client may only hold one home, one work and one school. Rather than
         * rejecting a second one, the existing entry is updated — which is what
         * "set my home address" means from the user's side, and avoids a
         * confusing "already exists" error for what feels like an edit.
         */
        if (in_array($data['kind'], SavedAddress::FIXED_KINDS, true)) {
            $address = $request->user()->savedAddresses()->updateOrCreate(
                ['kind' => $data['kind']],
                $data
            );

            return response()->json(['status' => true, 'data' => $address], 200);
        }

        $address = $request->user()->savedAddresses()->create($data);

        return response()->json(['status' => true, 'data' => $address], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $address = $request->user()->savedAddresses()->findOrFail($id);
        $address->update($this->validated($request));

        return response()->json(['status' => true, 'data' => $address]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = $request->user()->savedAddresses()->findOrFail($id);
        $address->delete();

        return response()->json(['status' => true, 'message' => 'Adresse supprimée.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'kind'     => ['required', Rule::in(['home', 'work', 'school', 'custom'])],
            // Required only for custom entries; the fixed kinds are labelled by
            // the app so the wording stays consistent and translatable.
            'label'    => ['nullable', 'required_if:kind,custom', 'string', 'max:60'],
            'address'  => ['required', 'string', 'max:255'],
            // Extra precision for the driver — see the migration for why this
            // is stored apart from the geocoded address.
            'detail'     => ['nullable', 'string', 'max:120'],
            'directions' => ['nullable', 'string', 'max:500'],
            'lat'      => ['nullable', 'numeric', 'between:-90,90'],
            'lng'      => ['nullable', 'numeric', 'between:-180,180'],
            'place_id' => ['nullable', 'string', 'max:255'],
        ], [
            'label.required_if' => 'Donnez un nom à cette adresse.',
        ]);
    }
}
