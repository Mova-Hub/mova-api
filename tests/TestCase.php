<?php

namespace Tests;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Field\AuthController as FieldAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign in as a field user, holding a `control/` token.
     *
     * Use these rather than `Sanctum::actingAs($user)` for any `User`.
     *
     * `Sanctum::actingAs()` defaults to **no** abilities, not to `['*']`, and it
     * mocks the token with `shouldIgnoreMissing`, so an unlisted ability makes
     * `can()` return null rather than failing loudly. Against the ability checks
     * in `EnsureField` and `EnsureStaff` that reads as a refusal, and the test
     * fails with a 403 that says nothing about why.
     *
     * More to the point, a token with no abilities cannot exist in production:
     * every mint site names one. A test that simulates one is testing a state
     * the system cannot reach, so these helpers make the test say which app's
     * token it is holding, which is the property being protected.
     */
    protected function actingAsField(User $user): User
    {
        return Sanctum::actingAs($user, [FieldAuthController::ABILITY]);
    }

    /** Sign in as staff, holding a `manager/` token. */
    protected function actingAsBackOffice(User $user): User
    {
        return Sanctum::actingAs($user, [AuthController::ABILITY]);
    }
}
