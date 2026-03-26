<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Http\Resources\CandidateResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{

    public function index(Request $request)
    {
        // On charge la relation 'emploi' pour afficher le titre du poste dans le tableau React
        $query = Candidate::with('emploi');

        // 1. Filtrer par Offre d'Emploi spécifique
        if ($emploiId = $request->query('emploi_id')) {
            $query->where('emploi_id', $emploiId);
        }

        // 2. Recherche globale (Nom, Prénom, Email)
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 3. Filtrer par Statut
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $query->orderBy('created_at', 'desc');
        $perPage = max((int) $request->query('per_page', 15), 1);

        return CandidateResource::collection($query->paginate($perPage));
    }

    public function show(Candidate $candidate)
    {
        return new CandidateResource($candidate->load('emploi'));
    }

    public function update(Request $request, Candidate $candidate)
    {
        // Les RH mettent généralement à jour le statut et les notes internes
        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(['pending', 'reviewed', 'accepted', 'rejected'])],
            'notes' => 'nullable|string',
        ]);

        $candidate->update($validated);

        return new CandidateResource($candidate->fresh('emploi'));
    }

    public function destroy(Candidate $candidate)
    {
        // Supprimer les fichiers associés avant de supprimer le candidat !
        if ($candidate->resume_path) {
            Storage::disk('public')->delete($candidate->resume_path);
        }
        if ($candidate->cover_letter_path) {
            Storage::disk('public')->delete($candidate->cover_letter_path);
        }

        $candidate->delete();
        return response()->noContent();
    }

    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'uuid|exists:candidates,id',
            'status' => ['required', Rule::in(['pending', 'reviewed', 'accepted', 'rejected'])],
        ]);

        $updatedCount = Candidate::whereIn('id', $validated['ids'])
            ->update(['status' => $validated['status']]);

        return response()->json(['updated' => $updatedCount]);
    }

    // --- PARTIE PUBLIQUE (Le Candidat postule) ---

    public function store(Request $request)
    {
        // Attention : Le frontend React doit envoyer un FormData (multipart/form-data)
        $validated = $request->validate([
            'emploi_id' => 'required|uuid|exists:emplois,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            // Validation stricte des fichiers : PDF, DOC, DOCX, max 5 Mo (5120 Ko)
            'resume' => 'required|file|mimes:pdf,doc,docx|max:5120',
            'cover_letter' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        $dataToSave = [
            'emploi_id' => $validated['emploi_id'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'status' => 'pending',
        ];

        // Traitement de l'Upload du CV
        if ($request->hasFile('resume')) {
            // Sauvegarde dans le dossier storage/app/public/resumes
            $path = $request->file('resume')->store('resumes', 'public');
            $dataToSave['resume_path'] = $path;
        }

        // Traitement de l'Upload de la lettre de motivation
        if ($request->hasFile('cover_letter')) {
            $path = $request->file('cover_letter')->store('cover_letters', 'public');
            $dataToSave['cover_letter_path'] = $path;
        }

        $candidate = Candidate::create($dataToSave);

        return (new CandidateResource($candidate))->response()->setStatusCode(201);
    }

}
