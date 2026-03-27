<?php

namespace App\Http\Controllers;

use App\Models\Emploi;
use App\Http\Resources\EmploiResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmploiController extends Controller
{
    public function index(Request $request)
    {
        $query = Emploi::query();

        // 1. Recherche globale (Titre, département, lieu)
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // 2. Filtres stricts
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }

        // 3. Tri (Du plus récent au plus ancien)
        $query->orderBy('created_at', 'desc');

        $perPage = max((int) $request->query('per_page', 15), 1);

        return EmploiResource::collection($query->paginate($perPage));
    }

    public function publicIndex(Request $request)
    {
        $query = Emploi::query();

        // 1. Recherche globale (Titre, département, lieu)
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // 2. Filtres stricts
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }

        // 3. Tri (Du plus récent au plus ancien)
        $query->orderBy('created_at', 'desc');

        $perPage = max((int) $request->query('per_page', 15), 1);

        return EmploiResource::collection($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'country' => 'nullable|string|max:255',
            'work_mode' => ['required', Rule::in(['onsite', 'hybrid', 'remote'])],
            'contract_type' => ['required', Rule::in(['full_time', 'part_time', 'cdi', 'cdd', 'freelance', 'internship', 'apprenticeship'])],
            'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
            'short_desc' => 'nullable|string',
            // Validation des tableaux
            'responsibilities' => 'nullable|array',
            'responsibilities.*' => 'string',
            'requirements' => 'nullable|array',
            'requirements.*' => 'string',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string',
        ]);

        $emploi = Emploi::create($validated);

        return new EmploiResource($emploi);
    }

    public function show(Emploi $emploi)
    {
        return new EmploiResource($emploi);
    }

    public function update(Request $request, Emploi $emploi)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'department' => 'sometimes|required|string|max:255',
            'location' => 'sometimes|required|string|max:255',
            'country' => 'nullable|string|max:255',
            'work_mode' => ['sometimes', 'required', Rule::in(['onsite', 'hybrid', 'remote'])],
            'contract_type' => ['sometimes', 'required', Rule::in(['full_time', 'part_time', 'cdi', 'cdd', 'freelance', 'internship', 'apprenticeship'])],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'open', 'closed'])],
            'short_desc' => 'nullable|string',
            'responsibilities' => 'nullable|array',
            'responsibilities.*' => 'string',
            'requirements' => 'nullable|array',
            'requirements.*' => 'string',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string',
        ]);

        $emploi->update($validated);

        return new EmploiResource($emploi);
    }

    public function destroy(Emploi $emploi)
    {
        $emploi->delete();
        return response()->noContent();
    }

    // Le fameux endpoint pour le statut en masse
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'uuid|exists:emplois,id',
            'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
        ]);

        $updatedCount = Emploi::whereIn('id', $validated['ids'])
            ->update(['status' => $validated['status']]);

        return response()->json(['updated' => $updatedCount]);
    }
}
