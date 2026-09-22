<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Document;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    public function create(Asset $asset)
    {
        $this->authorize('generateDocument', $asset);

        $asset->load(['category', 'status', 'company']);
        $templates = DocumentTemplate::where('is_active', true)->get();

        return view('documents.create', compact('asset', 'templates'));
    }

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('generateDocument', $asset);

        $validated = $request->validate([
            'document_type' => 'required|string',
            'document_number' => 'required|string|unique:documents',
            'title' => 'required|string',
            'template_id' => 'nullable|exists:document_templates,id',
            'file_path' => 'required|string',
            'file_type' => 'required|string',
            'generated_date' => 'nullable|date',
            'status' => 'nullable|in:draft,final',
        ]);

        $document = Document::create([
            'asset_id' => $asset->id,
            ...$validated,
            'generated_date' => $validated['generated_date'] ?? now(),
            'generated_by' => Auth::id(),
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'CREATE_DOCUMENT',
            'entity_type' => 'documents',
            'entity_id' => $document->id,
            'entity_identifier' => $document->document_number,
            'source' => 'Document',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Document created.');
    }

    public function destroy(Asset $asset, Document $document)
    {
        $this->authorize('generateDocument', $asset);

        if ($document->asset_id !== $asset->id) {
            abort(404);
        }

        $document->delete();

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'DELETE_DOCUMENT',
            'entity_type' => 'documents',
            'entity_id' => $document->id,
            'entity_identifier' => $document->document_number,
            'source' => 'Document',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Document deleted.');
    }
}
