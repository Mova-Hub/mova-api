<?php

namespace App\Http\Controllers;

use App\Models\Bus;
use App\Models\BusDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BusDocumentController extends Controller
{
    // GET /api/buses/{bus}/documents
    public function index(Bus $bus)
    {
        $docs = $bus->documents()->orderByDesc('created_at')->get();
        return response()->json($docs->map(fn($d) => $this->toArray($d)));
    }

    // POST /api/buses/{bus}/documents  (multipart/form-data)
    public function store(Request $request, Bus $bus)
    {
        $validated = $request->validate([
            'file'       => ['required', 'file', 'max:20480'],
            'name'       => ['required', 'string', 'max:255'],
            'type'       => ['nullable', 'string', 'in:carte_grise,assurance,visite_technique,permis,autre'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $path = $file->store("buses/{$bus->id}/documents", 'public');

        $doc = $bus->documents()->create([
            'name'        => $validated['name'],
            'type'        => $validated['type'] ?? 'autre',
            'file_path'   => $path,
            'mime_type'   => $file->getMimeType(),
            'size_kb'     => (int) ceil($file->getSize() / 1024),
            'expires_at'  => $validated['expires_at'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($this->toArray($doc), 201);
    }

    // DELETE /api/buses/{bus}/documents/{document}
    public function destroy(Bus $bus, BusDocument $document)
    {
        if ($document->bus_id !== $bus->id) {
            abort(404);
        }

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }

    private function toArray(BusDocument $d): array
    {
        return [
            'id'          => $d->id,
            'bus_id'      => $d->bus_id,
            'name'        => $d->name,
            'type'        => $d->type,
            'file_url'    => $d->file_path ? Storage::disk('public')->url($d->file_path) : null,
            'mime_type'   => $d->mime_type,
            'size_kb'     => $d->size_kb,
            'expires_at'  => $d->expires_at?->toDateString(),
            'uploaded_by' => $d->uploaded_by,
            'created_at'  => $d->created_at?->toIso8601String(),
        ];
    }
}
