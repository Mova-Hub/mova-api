<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Support\PerformsAuditedBulkUpdates;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    use PerformsAuditedBulkUpdates;

    // GET /api/staff?search=&status=&role=&per_page=15
    public function index(Request $request)
    {
        /*
         * Every account that can log in — including the field roles.
         *
         * `LOGIN_ROLES`, not `STAFF_ROLES`, and the difference is the point of
         * the split: the back-office CREATES coordinators and controllers, but
         * those accounts cannot reach the back-office themselves. Who may
         * manage them is one question; what they may open is another.
         *
         * Fleet records (`driver`, `conductor`, `owner`) stay out — they are
         * people in the system, not accounts, and `PersonController` owns them.
         */
        $q = User::query()
            ->whereIn('role', User::LOGIN_ROLES);

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

        /*
         * Optional role filter — and it accepts a COMMA-SEPARATED list.
         *
         * The manager's coordinator picker asks for `role=coordinator,admin,agent`:
         * a coordinator is the obvious choice, but an agent covering a Saturday
         * has to be assignable too. `array_intersect` against `LOGIN_ROLES` is
         * what stops the parameter becoming "show me any role you like".
         */
        if ($role = $request->string('role')->toString()) {
            $requested = array_map('trim', explode(',', $role));
            $q->whereIn('role', array_values(array_intersect(User::LOGIN_ROLES, $requested)));
        }

        $perPage = max((int) $request->input('per_page', 50), 1);

        return StaffResource::collection($q->latest()->paginate($perPage));
    }

    // POST /api/staff
    public function store(StoreStaffRequest $request)
    {
        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // default status
        $data['status'] = $data['status'] ?? 'active';

        $staff = User::create($data);

        return new StaffResource($staff);
    }

    // GET /api/staff/{staff}
    public function show(User $staff)
    {
        $this->assertStaff($staff);
        return new StaffResource($staff);
    }

    // PUT/PATCH /api/staff/{staff}
    public function update(UpdateStaffRequest $request, User $staff)
    {
        $this->assertStaff($staff);

        $data = $request->validated();
        if (array_key_exists('password', $data)) {
            $data['password'] = $data['password']
                ? Hash::make($data['password'])
                : null;
        }

        $staff->update($data);

        return new StaffResource($staff);
    }

    // DELETE /api/staff/{staff}
    // If you prefer not to hard-delete, you can set status=inactive instead.
    public function destroy(User $staff)
    {
        $this->assertStaff($staff);
        $staff->delete(); // hard delete
        return response()->noContent();
    }

    // POST /api/staff/bulk-status  { ids:[], status:"active|inactive|suspended" }
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'    => ['required','array','min:1','max:200'],
            'ids.*'  => ['integer','exists:users,id'],
            'status' => ['required', Rule::in(['active','inactive','suspended'])],
        ]);

        /*
         * Suspending staff in bulk is among the most consequential actions in
         * the system — it revokes back-office access — and it produced no audit
         * record at all, because `Builder::update()` fires no model events.
         *
         * It also reported `count($ids)` as the number updated regardless of
         * how many rows the `role` filter actually matched, so passing a
         * driver's id inflated the figure. Now it returns what really changed.
         */
        $updated = $this->auditedBulkUpdate(
            User::whereIn('id', $validated['ids'])->whereIn('role', User::LOGIN_ROLES),
            ['status' => $validated['status']],
            'staff.bulk_status',
            ['ids' => $validated['ids']],
        );

        return response()->json(['updated' => $updated]);
    }

    /**
     * Promote, demote, or move somebody between the office and the field.
     *
     * POST /api/staff/role  { id, role }
     *
     * The role set is `LOGIN_ROLES`, so an agent can be made a coordinator for a
     * season and moved back. Deliberately NOT the fleet roles: turning a
     * back-office account into a `driver` would strip its login by way of a
     * dropdown, which is not a thing anyone means to do.
     */
    public function setRole(Request $request)
    {
        $validated = $request->validate([
            'id'   => ['required','integer','exists:users,id'],
            'role' => ['required', Rule::in(User::LOGIN_ROLES)],
        ]);

        $staff = User::findOrFail($validated['id']);
        $this->assertStaff($staff);

        $staff->update(['role' => $validated['role']]);

        return new StaffResource($staff);
    }

    /**
     * 404, not 403, for anyone this endpoint does not own.
     *
     * A fleet record reached through /staff should look absent rather than
     * forbidden — a 403 confirms the id exists, which turns the endpoint into a
     * membership oracle over the whole users table.
     */
    private function assertStaff(User $staff): void
    {
        if (!in_array($staff->role, User::LOGIN_ROLES, true)) {
            abort(404);
        }
    }
}
