<?php

namespace App\Http\Controllers;

use App\Http\Resources\PersonResource;
use App\Models\Bus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PersonController extends Controller
{
    // GET /api/person?search=&status=&role=&per_page=50
    public function index(Request $request)
    {
        $q = User::query()
            ->whereIn('role', ['driver', 'conductor', 'owner']);

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name',  'like', "%{$search}%")
                   ->orWhere('email', 'like', "%{$search}%")
                   ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        if ($role = $request->string('role')->toString()) {
            $q->whereIn('role', array_intersect(['driver', 'conductor', 'owner'], [$role]));
        }

        $perPage = max((int) $request->input('per_page', 50), 1);

        return PersonResource::collection($q->latest()->paginate($perPage));
    }

    // POST /api/person
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'unique:users,email'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'role'       => ['required', Rule::in(['driver', 'conductor', 'owner'])],
            'status'     => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'license_no' => ['nullable', 'string', 'max:100'],
            'permit_expiration_date' => ['nullable', 'date'],
            'address'                   => ['nullable', 'array'],
            'address.street'            => ['nullable', 'string'],
            'address.quartier'          => ['nullable', 'string'],
            'address.arrondissement'    => ['nullable', 'string'],
            'address.city'              => ['nullable', 'string'],
            'address.department'        => ['nullable', 'string'],
            'address.country'           => ['nullable', 'string'],
            'password'   => ['nullable', 'string', 'min:8'],
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $validated['status'] = $validated['status'] ?? 'inactive';

        $person = User::create($validated);

        return new PersonResource($person);
    }

    // GET /api/person/{person}
    public function show(User $person)
    {
        $this->assertStaff($person);

        if (in_array($person->role, ['driver', 'conductor'], true)) {
            $person->load(['busesAsDriver', 'busesAsConductor']);
        } elseif ($person->role === 'owner') {
            $person->load('ownedBuses');
        }

        return new PersonResource($person);
    }

    // PUT/PATCH /api/person/{person}
    public function update(Request $request, User $person)
    {
        $this->assertStaff($person);

        $validated = $request->validate([
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', Rule::unique('users', 'email')->ignore($person->id)],
            'phone'      => ['nullable', 'string', 'max:50'],
            'role'       => ['sometimes', 'required', Rule::in(['driver', 'conductor', 'owner'])],
            'status'     => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'license_no' => ['nullable', 'string', 'max:100'],
            'permit_expiration_date' => ['nullable', 'date'],
            'address'                   => ['nullable', 'array'],
            'address.street'            => ['nullable', 'string'],
            'address.quartier'          => ['nullable', 'string'],
            'address.arrondissement'    => ['nullable', 'string'],
            'address.city'              => ['nullable', 'string'],
            'address.department'        => ['nullable', 'string'],
            'address.country'           => ['nullable', 'string'],
            'password'   => ['nullable', 'string', 'min:8'],
        ]);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = $validated['password']
                ? Hash::make($validated['password'])
                : null;
        }

        $person->update($validated);

        return new PersonResource($person);
    }

    // DELETE /api/person/{person}
    public function destroy(User $person)
    {
        $this->assertStaff($person);

        // Release from assigned buses
        Bus::where('assigned_driver_id', $person->id)->update(['assigned_driver_id' => null]);
        Bus::where('assigned_conductor_id', $person->id)->update(['assigned_conductor_id' => null]);
        Bus::where('operator_id', $person->id)->update(['operator_id' => null]);

        $person->delete();

        return response()->noContent();
    }

    // POST /api/person/bulk-status  { ids:[], status:"active|inactive|suspended" }
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer', 'exists:users,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        User::whereIn('id', $validated['ids'])
            ->whereIn('role', ['driver', 'conductor', 'owner'])
            ->update(['status' => $validated['status']]);

        return response()->json(['updated' => count($validated['ids'])]);
    }

    // POST /api/person/role  { id: number, role: string }
    public function setRole(Request $request)
    {
        $validated = $request->validate([
            'id'   => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::in(['driver', 'conductor', 'owner'])],
        ]);

        $person = User::findOrFail($validated['id']);
        $this->assertStaff($person);
        $person->update(['role' => $validated['role']]);

        return new PersonResource($person);
    }

    // POST /api/person/{person}/avatar  (multipart)
    public function uploadAvatar(Request $request, User $person)
    {
        $this->assertStaff($person);

        $request->validate([
            'avatar' => ['nullable', 'image', 'max:4096'],
        ]);

        // Delete old avatar if stored locally
        if ($person->getRawOriginal('avatar_url') && !str_starts_with($person->getRawOriginal('avatar_url'), 'http')) {
            Storage::disk('public')->delete($person->getRawOriginal('avatar_url'));
        }

        $path = $request->file('avatar')->store("avatars/people", 'public');
        $person->update(['avatar_url' => $path]);

        return new PersonResource($person);
    }

    private function assertStaff(User $person): void
    {
        if (!in_array($person->role, ['driver', 'conductor', 'owner'], true)) {
            abort(404);
        }
    }
}
