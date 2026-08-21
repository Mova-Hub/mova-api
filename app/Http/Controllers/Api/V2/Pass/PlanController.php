<?php

namespace App\Http\Controllers\Api\V2\Pass;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pass\PassPlanResource;
use App\Models\PassPlan;

/**
 * The catalogue the app is allowed to show.
 *
 * `available()` filters to active plans in display order — an inactive plan
 * must never reach a phone, because a released build will happily let someone
 * buy whatever it was told about.
 */
class PlanController extends Controller
{
    public function __invoke()
    {
        return PassPlanResource::collection(PassPlan::available()->get());
    }
}
