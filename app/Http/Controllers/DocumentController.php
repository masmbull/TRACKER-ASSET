<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Company;
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
     * Every generated document, across all assets (PRD §43).
     *
     * The Documents tab on the asset page only ever answered "what has this one
     * asset got?". There was no way to ask "show me every BAST we issued this
     * quarter" or to find last month's invoice for an asset somebody else
     * registered - the only route to a document was a direct link, which means a
     * document that left the browser tab became unreachable from the UI.
     *
     * @param  Request  $request  'type' narrows to one document type, 'q' searches
     *                            the number, the title and the asset id.
     */
    public function index(Request $request)
    {
        // Asset-linked documents follow the asset policy. Standalone documents
        // use the same viewAny boundary as the register itself.
        $this->authorize('viewAny', Asset::class);
        $canGenerate = $request->user()->can('create', Asset::class);

        $type = $request->query('type');
        $search = trim((string) $request->query('q', ''));
        $totalDocuments = Document::query()->count();

        $documents = Document::query()
            ->with(['asset', 'generator'])
            ->when($type, fn ($query) => $query->where('document_type', $type))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%' . $search . '%';
                $query->where(function ($query) use ($like): void {
                    $query->where('document_number', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhereHas('asset', fn ($asset) => $asset->where('asset_id', 'like', $like));
                });
            })
            ->orderByDesc('generated_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'types' => array_keys((array) config('documents.types', [])),
            'type' => $type,
            'search' => $search,
            'totalDocuments' => $totalDocuments,
            'canGenerate' => $canGenerate,
            'assets' => $canGenerate
                ? Asset::query()->orderBy('asset_id')->get(['id', 'asset_id', 'brand', 'model'])
                : collect(),
        ]);
    }

    public function createFromIndex(Request $request)
    {
        $this->authorize('create', Asset::class);

        $validated = $request->validate([
            'asset_id' => ['required', 'string', Rule::exists('assets', 'asset_id')],
        ]);

        $asset = Asset::query()->where('asset_id', $validated['asset_id'])->firstOrFail();
        $this->authorize('generateDocument', $asset);

        return redirect()->route('documents.generate', $asset);
    }

    public function generateBlank()
    {
        $this->authorize('create', Asset::class);

        $types = (array) config('documents.types', []);
        $selectedType = 'FAT';

        return view('documents.generate', [
            'asset' => null,
            'transfers' => collect(),
            'senderUser' => null,
            'senderName' => null,
            'senderDepartment' => null,
            'senderEmail' => null,
            'types' => $types,
            'selectedType' => $selectedType,
            'selectedTemplateId' => null,
            'checklist' => (array) config('documents.checklists.' . $selectedType, []),
            'kelengkapan' => (array) config('documents.kelengkapan.' . $selectedType, []),
            'copy' => $this->copyFor($selectedType),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'templates' => DocumentTemplate::where('is_active', true)->orderBy('document_type')->get(),
        ]);
    }

    /**
     * Auto-generate form: everything is prefilled from the register (PRD §32).
     */
    public function generate(Request $request, Asset $asset)
    {
        $this->authorize('generateDocument', $asset);

        $asset->load(['category', 'status', 'company', 'location', 'currentUser', 'adminItPic']);

        $types = (array) config('documents.types', []);

        // The type is part of the URL because the form is not the same form for
        // every type. It used to be ignored and the select always defaulted to
        // FAT, so linking straight to a BAST silently produced a FAT form with
        // the wrong sections and the wrong labels.
        $selectedTemplate = null;
        if ($request->filled('template_id')) {
            $selectedTemplate = DocumentTemplate::query()
                ->whereKey($request->integer('template_id'))
                ->where('is_active', true)
                ->first();
        }

        $selected = (string) ($selectedTemplate?->document_type ?? $request->query('document_type', 'FAT'));
        if (! in_array($selected, $types, true)) {
            $selected = 'FAT';
        }

        // One definition of who is signing, shared with the PDF. The view used to
        // re-derive this itself and the two disagreed the first time the rule
        // changed - which is how a BAST once arrived pre-filled with the IT PIC
        // while the service said somebody else was signing it.
        $parties = app(DocumentGenerationService::class)->partiesFor($asset, $selected, null);

        return view('documents.generate', [
            'asset' => $asset,
            'transfers' => $asset->transfers()->with(['fromUser', 'toUser'])->orderByDesc('id')->get(),
            // Who the asset went to first and who holds it now, so the form can
            // name both parties instead of asking somebody to retype them.
            //
            // This is the difference between generating a transfer form and
            // copy-pasting one: the register already knows that laptop A moved
            // from person A to person B, and every field on the document was
            // being typed again by hand every time one was needed.
            'senderUser' => $parties['sender'],
            'senderName' => $parties['sender']?->name ?? Auth::user()?->name,
            'senderDepartment' => $parties['sender']?->department,
            'senderEmail' => $parties['sender']?->email,
            'types' => $types,
            'selectedType' => $selected,
            'selectedTemplateId' => $selectedTemplate?->id,
            // Only this type's checklist. A TRANSFER has none, and rendering the
            // FAT list under a "Checklist FAT" heading on it is a lie about the
            // document being produced.
            'checklist' => (array) config('documents.checklists.' . $selected, []),
            // Per-item completeness for this type, rendered as Ada / Tidak Ada.
            'kelengkapan' => (array) config('documents.kelengkapan.' . $selected, []),
            'copy' => $this->copyFor($selected),
            // Entity is a choice from master data, not free text. Typing "PT Mito"
            // against "PT. Mito" makes the sender and receiver look like different
            // organisations on a signed document.
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'templates' => DocumentTemplate::where('is_active', true)->orderBy('document_type')->get(),
        ]);
    }

    /**
     * Wording for a document type, falling back to the FAT copy.
     *
     * The fallback matters: a type nobody has written specific wording for should
     * say something reasonable rather than render an empty label next to a date
     * box.
     */
    private function copyFor(string $type): array
    {
        $default = (array) config('documents.copy.FAT', []);
        $specific = (array) config('documents.copy.' . $type, []);

        return array_merge($default, $specific);
    }

    /**
     * Generate the document + PDF atomically and redirect to the preview.
     */
    public function storeGenerated(Request $request, Asset $asset, DocumentGenerationService $documents)
    {
        $this->authorize('generateDocument', $asset);

        $validated = $this->validateGenerationInput($request);

        if (! $this->templateMatchesDocumentType($validated)) {
            return back()->withInput()->withErrors([
                'template_id' => 'Choose an active template that matches the selected document type.',
            ]);
        }

        $document = $documents->generate($asset, $validated['document_type'], $validated, $request->user());

        return redirect()
            ->route('documents.preview', $document)
            ->with('success', "Document {$document->document_number} generated.");
    }

    public function storeBlank(Request $request, DocumentGenerationService $documents)
    {
        $this->authorize('create', Asset::class);

        $validated = $this->validateGenerationInput($request, allowTransfer: false);

        if (! $this->templateMatchesDocumentType($validated)) {
            return back()->withInput()->withErrors([
                'template_id' => 'Choose an active template that matches the selected document type.',
            ]);
        }

        $document = $documents->generate(null, $validated['document_type'], $validated, $request->user());

        return redirect()
            ->route('documents.preview', $document)
            ->with('success', "Document {$document->document_number} generated.");
    }

    private function validateGenerationInput(Request $request, bool $allowTransfer = true): array
    {
        $rules = [
            'document_type' => ['required', Rule::in((array) config('documents.types', []))],
            'template_id' => 'nullable|exists:document_templates,id',
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
            'receiver_employee_id' => 'nullable|string|max:100',
            'receiver_entity' => 'nullable|string|max:150',
            'receiver_location' => 'nullable|string|max:150',
            'receiver_division' => 'nullable|string|max:150',
            'receiver_email' => 'nullable|email|max:150',
            'receiver_phone' => 'nullable|string|max:100',
            'asset_type' => 'nullable|string|max:150',
            'asset_brand_model' => 'nullable|string|max:150',
            'asset_serial_number' => 'nullable|string|max:150',
            'asset_tag' => 'nullable|string|max:150',
            'asset_processor' => 'nullable|string|max:150',
            'asset_ram' => 'nullable|string|max:80',
            'asset_storage' => 'nullable|string|max:80',
            'asset_os_version' => 'nullable|string|max:255',
            'asset_storage_type' => 'nullable|string|max:50',
            'asset_storage_capacity' => 'nullable|string|max:50',
            'asset_accessories' => 'nullable|string|max:255',
            'nomor_surat' => 'nullable|string|max:100',
            'kelengkapan' => 'nullable|array',
            'kelengkapan.*' => 'nullable|in:ada,tidak',
            'signatory_menyerahkan' => 'nullable|string|max:150',
            'signatory_menerima' => 'nullable|string|max:150',
            'signatory_mengetahui' => 'nullable|string|max:150',
            'signatory_it' => 'nullable|string|max:150',
            'signatory_image' => 'nullable|file|mimes:png,jpg,jpeg|max:2048',
            'checklist' => 'nullable|array',
            'checklist.*' => 'nullable|in:pass,fail,na',
            'notes' => 'nullable|string',
        ];

        if (! $allowTransfer) {
            $rules['transfer_id'] = 'prohibited';
        }

        return $request->validate($rules);
    }

    private function templateMatchesDocumentType(array $validated): bool
    {
        if (empty($validated['template_id'])) {
            return true;
        }

        $template = DocumentTemplate::query()->findOrFail($validated['template_id']);

        return $template->is_active && $template->document_type === $validated['document_type'];
    }

    private function authorizeDocumentView(Document $document): void
    {
        if ($document->asset_id === null) {
            $this->authorize('viewAny', Asset::class);

            return;
        }

        $this->authorize('view', $document->asset()->firstOrFail());
    }

    /**
     * On-screen preview with download/print actions (PRD §34).
     */
    public function preview(Document $document)
    {
        $this->authorizeDocumentView($document);

        $document->load(['asset', 'transfer', 'generator']);

        return view('documents.preview', compact('document'));
    }

    /**
     * Metadata the modal preview needs before it fetches the PDF itself.
     *
     * Split from the file response on purpose: the viewer renders into a canvas,
     * so it needs the byte URL and the title separately, and a JSON round trip
     * lets it show a real error instead of a blank iframe when the file is gone.
     */
    public function previewData(Document $document)
    {
        $this->authorizeDocumentView($document);

        abort_unless($document->file_path && Storage::exists($document->file_path), 404);

        return response()->json([
            'title' => $document->title,
            'document_number' => $document->document_number,
            'pages' => 0,
            'file_url' => route('documents.download', $document) . '?inline=1',
            'download_url' => route('documents.download', $document),
        ]);
    }

    /**
     * Stream the generated PDF (inline for the preview, or as a download).
     */
    public function download(Request $request, Document $document)
    {
        $this->authorizeDocumentView($document);

        abort_unless($document->file_path && Storage::exists($document->file_path), 404);

        $path = Storage::path($document->file_path);
        $fileName = (Str::slug($document->document_number) ?: 'document') . '.pdf';
        $headers = ['Content-Type' => 'application/pdf'];

        if ($request->boolean('inline')) {
            // Explicit, not implicit: response()->file() does set this, but the
            // preview depends on it, so it is stated here and pinned by a test.
            // Anything that changes the default would otherwise silently turn the
            // viewer's PDF into a download prompt.
            return response()->file($path, $headers + [
                'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            ]);
        }

        return response()->download($path, $fileName, $headers);
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
