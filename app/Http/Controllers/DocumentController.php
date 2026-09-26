<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Services\DocumentGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /**
     * Auto-generate form: everything is prefilled from the register (PRD §32).
     */
    public function generate(Asset $asset)
    {
        $this->authorize('generateDocument', $asset);

        $asset->load(['category', 'status', 'company', 'location', 'currentUser']);

        return view('documents.generate', [
            'asset' => $asset,
            'transfers' => $asset->transfers()->with(['fromUser', 'toUser'])->orderByDesc('id')->get(),
            'types' => (array) config('documents.types', []),
            'checklist' => (array) config('documents.fat_checklist', []),
        ]);
    }

    /**
     * Generate the document + PDF atomically and redirect to the preview.
     */
    public function storeGenerated(Request $request, Asset $asset, DocumentGenerationService $documents)
    {
        $this->authorize('generateDocument', $asset);

        $validated = $request->validate([
            'document_type' => ['required', Rule::in((array) config('documents.types', []))],
            'transfer_id' => 'nullable|exists:asset_transfers,id',
            'document_date' => 'nullable|date',
            'handover_date' => 'nullable|date',
            'received_date' => 'nullable|date',
            'purpose' => 'nullable|string|max:255',
            'statement' => 'nullable|string',
            'sender_employee_id' => 'nullable|string|max:100',
            'sender_entity' => 'nullable|string|max:150',
            'sender_division' => 'nullable|string|max:150',
            'sender_email' => 'nullable|email|max:150',
            'sender_phone' => 'nullable|string|max:100',
            'receiver_entity' => 'nullable|string|max:150',
            'receiver_division' => 'nullable|string|max:150',
            'receiver_email' => 'nullable|email|max:150',
            'asset_type' => 'nullable|string|max:150',
            'asset_brand_model' => 'nullable|string|max:150',
            'asset_serial_number' => 'nullable|string|max:150',
            'asset_tag' => 'nullable|string|max:150',
            'asset_processor' => 'nullable|string|max:150',
            'asset_ram' => 'nullable|string|max:80',
            'asset_storage' => 'nullable|string|max:80',
            'asset_accessories' => 'nullable|string|max:255',
            'signatory_menyerahkan' => 'nullable|string|max:150',
            'signatory_menerima' => 'nullable|string|max:150',
            'signatory_mengetahui' => 'nullable|string|max:150',
            'signatory_it' => 'nullable|string|max:150',
            'checklist' => 'nullable|array',
            'checklist.*' => 'nullable|in:pass,fail,na',
            'notes' => 'nullable|string',
        ]);

        $document = $documents->generate($asset, $validated['document_type'], $validated, $request->user());

        return redirect()
            ->route('documents.preview', $document)
            ->with('success', "Document {$document->document_number} generated.");
    }

    /**
     * On-screen preview with download/print actions (PRD §34).
     */
    public function preview(Document $document)
    {
        $this->authorize('view', $document->asset()->firstOrFail());

        $document->load(['asset', 'transfer', 'generator']);

        return view('documents.preview', compact('document'));
    }

    /**
     * Stream the generated PDF (inline for the preview iframe, or as a download).
     */
    public function download(Request $request, Document $document)
    {
        $this->authorize('view', $document->asset()->firstOrFail());

        abort_unless($document->file_path && Storage::exists($document->file_path), 404);

        $path = Storage::path($document->file_path);
        $fileName = (Str::slug($document->document_number) ?: 'document').'.pdf';
        $headers = ['Content-Type' => 'application/pdf'];

        return $request->boolean('inline')
            ? response()->file($path, $headers)
            : response()->download($path, $fileName, $headers);
    }

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
