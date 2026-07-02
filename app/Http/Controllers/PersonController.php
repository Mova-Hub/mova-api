<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PersonController extends Controller
{
    // GET /api/staff?search=&status=&role=&per_page=15
    public function index(Request $request)
    {
        $q = User::query()
            ->whereIn('role', ['driver', 'conductor', 'owner']);

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name','like',"%{$search}%")
                   ->orWhere('email','like',"%{$search}%")
                   ->orWhere('phone','like',"%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        // optional: filter specific staff role (agent or admin)
        if ($role = $request->string('role')->toString()) {
            $q->whereIn('role', array_intersect(['driver', 'conductor', 'owner'], [$role]));
        }

        $perPage = max((int) $request->input('per_page', 50), 1);

        return PersonResource::collection($q->latest()->paginate($perPage));
    }

    // POST /api/staff
    public function store(StorePersonRequest $request)
    {
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // default status
        $data['status'] = $data['status'] ?? 'inactive';

        $person = User::create($data);

        return new PersonResource($person);
    }

    // GET /api/staff/{person}
    public function show(User $person)
    {
        $this->assertStaff($person);
        return new PersonResource($person);
    }

    // PUT/PATCH /api/staff/{person}
    public function update(UpdatePersonRequest $request, User $person)
    {
        $this->assertStaff($person);

        $data = $request->validated();
        if (array_key_exists('password', $data)) {
            $data['password'] = $data['password']
                ? Hash::make($data['password'])
                : null;
        }

        $person->update($data);

        return new PersonResource($person);
    }

    // DELETE /api/staff/{person}
    // If you prefer not to hard-delete, you can set status=inactive instead.
    public function destroy(User $person)
    {
        $this->assertStaff($person);
        $person->delete(); // hard delete
        return response()->noContent();
    }

    // POST /api/staff/bulk-status  { ids:[], status:"active|inactive|suspended" }
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'    => ['required','array','min:1'],
            'ids.*'  => ['integer','exists:users,id'],
            'status' => ['required', Rule::in(['active','inactive','suspended'])],
        ]);

        User::whereIn('id', $validated['ids'])
            ->whereIn('role', ['driver','conductor','owner'])
            ->update(['status' => $validated['status']]);

        return response()->json(['updated' => count($validated['ids'])]);
    }

    // POST /api/staff/role  { id: number, role: "agent|admin" } (promote/demote)
    public function setRole(Request $request)
    {
        $validated = $request->validate([
            'id'   => ['required','integer','exists:users,id'],
            'role' => ['required', Rule::in(['driver', 'conductor', 'owner'])],
        ]);

        $person = User::findOrFail($validated['id']);
        $this->assertStaff($person);

        $person->update(['role' => $validated['role']]);

        return new PersonResource($person);
    }

    private function assertStaff(User $person): void
    {
        if (!in_array($person->role, ['driver', 'conductor', 'owner'], true)) {
            abort(404); // hide non-staff users from this endpoint
        }
    }
}
