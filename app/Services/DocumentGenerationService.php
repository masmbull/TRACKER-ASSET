<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Document;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates FAT/BAST/etc. documents from asset and transfer data (PRD §32–§35).
 *
 * No manual copy/paste: every field is populated from the register. The payload
 * is persisted so the document can be re-rendered at any time.
 */
class DocumentGenerationService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * Create the document record plus its PDF, atomically.
     */
    public function generate(Asset $asset, string $type, array $input, ?User $actor = null): Document
    {
        $type = Str::upper(trim($type));

        abort_unless(in_array($type, (array) config('documents.types', []), true), 422, "Unsupported document type: {$type}");

        $asset->loadMissing(['company', 'category', 'location', 'status', 'currentUser']);

        $transfer = null;

        if (! empty($input['transfer_id'])) {
            $transfer = $asset->transfers()->find((int) $input['transfer_id']);
        }

        $payload = $this->buildPayload($asset, $type, $input, $transfer);

        return DB::transaction(function () use ($asset, $type, $payload, $transfer, $actor, $input) {
            $number = $this->numbers->next($type);
            $fileName = Str::slug($number).'.pdf';
            $relativePath = trim((string) config('documents.storage_path', 'documents'), '/').'/'.$fileName;

            Storage::put($relativePath, $this->renderPdf($type, $payload, $number, $input));

            $signaturePath = null;
            if (! empty($input['signatory_image']) && $input['signatory_image'] instanceof UploadedFile) {
                $sigName = 'signatures/'.Str::slug($number).'-'.Str::random(8).'.'.$input['signatory_image']->extension();
                $signaturePath = Storage::put($sigName, $input['signatory_image']);
            }

            $document = Document::create([
                'asset_id' => $asset->id,
                'transfer_id' => $transfer?->id,
                'document_type' => $type,
                'document_number' => $number,
                'nomor_surat' => $input['nomor_surat'] ?? null,
                'title' => $payload['title'],
                'template_id' => $payload['template_id'],
                'payload' => $payload + ['signature_image' => $signaturePath, 'nomor_surat' => $input['nomor_surat'] ?? null],
                'file_path' => $relativePath,
                'file_type' => 'pdf',
                'generated_date' => now()->toDateString(),
                'generated_by' => $actor?->id ?? Auth::id(),
                'status' => 'final',
                'signature_image' => $signaturePath,
            ]);

            ActivityLog::create([
                'actor_id' => $actor?->id ?? Auth::id(),
                'action' => 'GENERATE_DOCUMENT',
                'entity_type' => 'documents',
                'entity_id' => $document->id,
                'entity_identifier' => $document->document_number,
                'new_value' => json_encode(['type' => $type, 'asset' => $asset->asset_id]),
                'source' => 'Document',
            ]);

            return $document;
        });
    }

    /**
     * Everything the document needs, populated from the register (PRD §32).
     */
    public function buildPayload(Asset $asset, string $type, array $input, ?AssetTransfer $transfer): array
    {
        // Dates are rendered in Indonesian on the face of the document. The paper
        // form writes "14 September 2026"; the PDF used to print the raw ISO
        // string ("2026-09-14") from the column, which is a storage format, not
        // something to sign. Formatted once here so every template reads the same.
        $handoverDate = $input['handover_date'] ?? $input['document_date'] ?? now()->toDateString();
        $receivedDate = $input['received_date'] ?? null;

        return [
            'type' => $type,
            'title' => $this->titleFor($type, $asset),
            'template_id' => $input['template_id'] ?? null,
            'date' => $input['document_date'] ?? now()->toDateString(),
            'date_long' => $this->longDate($input['document_date'] ?? now()->toDateString()),
            'asset' => [
                'asset_id' => $asset->asset_id,
                'category' => $asset->category?->name,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'serial_number' => $asset->serial_number,
                'hostname' => $asset->hostname,
                'processor' => $asset->processor,
                'ram' => $asset->ram,
                'storage' => $asset->storage,
                'storage_type' => $asset->storage_type,
                'storage_capacity' => $asset->storage_capacity,
                'os_version' => $asset->os_version,
                'status' => $asset->status?->name,
                'type' => $input['asset_type'] ?? $asset->category?->name,
                'brand_model' => $input['asset_brand_model'] ?? trim(($asset->brand ?? '').' '.($asset->model ?? '')),
                'serial' => $input['asset_serial_number'] ?? $asset->serial_number,
                'tag' => $input['asset_tag'] ?? $asset->asset_id,
                'processor_detail' => $input['asset_processor'] ?? $asset->processor,
                'ram_detail' => $input['asset_ram'] ?? $asset->ram,
                'storage_detail' => $input['asset_storage'] ?? $asset->storage,
                'storage_type_detail' => $input['asset_storage_type'] ?? $asset->storage_type,
                'storage_capacity_detail' => $input['asset_storage_capacity'] ?? $asset->storage_capacity,
                'accessories' => $input['asset_accessories'] ?? null,
            ],
            'company' => $asset->company?->name,
            'location' => $input['receiver_location'] ?? $transfer?->toLocation?->name ?? $asset->location?->name,
            'current_user' => $asset->currentUser?->name,
            'parties' => $this->partiesForPayload($asset, $input, $transfer, $type),
            'transfer' => $transfer ? [
                'number' => $transfer->transfer_number,
                'date' => $transfer->transfer_date?->format('Y-m-d'),
                'from_user' => $transfer->fromUser?->name,
                'from_department' => $transfer->from_department,
                'from_location' => $transfer->fromLocation?->name,
                'to_user' => $transfer->toUser?->name,
                'to_department' => $transfer->to_department,
                'to_location' => $transfer->toLocation?->name,
                'condition' => $transfer->condition,
                'accessories' => $transfer->accessories,
            ] : null,
            'handover' => [
                'date' => $handoverDate,
                'date_long' => $this->longDate($handoverDate),
                'received_date' => $receivedDate,
                'received_long' => $receivedDate ? $this->longDate($receivedDate) : null,
                'purpose' => $input['purpose'] ?? null,
            ],
            // The date the signature block is dated with. Separate from the
            // handover date only so a signed-it-later copy has somewhere to say so.
            'signature_date' => $this->longDate($input['received_date'] ?? $handoverDate),
            'signatories' => [
                'menyerahkan' => $input['signatory_menyerahkan'] ?? null,
                'menerima' => $input['signatory_menerima'] ?? null,
                'mengetahui' => $input['signatory_mengetahui'] ?? null,
                'it' => $input['signatory_it'] ?? null,
            ],
            'checklist' => $this->checklistFor($type, $input),
            // Completeness answers keyed by the item label, so the document prints
            // "Charger: Ada" and not a bare list of keys.
            'kelengkapan' => $this->kelengkapanFor($type, $input),
            'notes' => $input['notes'] ?? null,
            'statement' => $input['statement'] ?? $this->statementFor($type),
            'signature_image' => $input['signatory_image'] ?? null,
        ];
    }

    /** Indonesian long form date, e.g. "14 September 2026", or the raw value if unparseable. */
    private function longDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->locale('id')->translatedFormat('d F Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Physical completeness, per item, per type. Empty answers become "-" rather
     * than "Tidak Ada", so a document does not claim an accessory is missing just
     * because nobody ticked the box.
     */
    private function kelengkapanFor(string $type, array $input): array
    {
        $items = (array) config('documents.kelengkapan.'.$type, []);
        $answers = (array) ($input['kelengkapan'] ?? []);

        $result = [];

        foreach ($items as $index => $label) {
            $answer = $answers[$index] ?? null;

            $result[$label] = in_array($answer, ['ada', 'tidak'], true) ? $answer : '';
        }

        return $result;
    }

    /** Default statement wording for a type, so E. Pernyataan is never blank. */
    private function statementFor(string $type): string
    {
        return (string) config('documents.copy.'.$type.'.statement', config('documents.copy.FAT.statement', ''));
    }

    /**
     * The two people and organisations a document is handed between.
     *
     * These fields were validated and then dropped: `buildPayload` never read
     * them and no PDF template rendered them, so the whole "Data Penyerah" and
     * "Data Penerima" section of the form went nowhere. A signed document that
     * omits who handed it over and who received it is not a handover record, and
     * whoever filled the form had no way of telling.
     *
     * Falls back to the register rather than to blanks, per the one-asset rule:
     * the sender is normally the asset's own company and the receiver its current
     * holder. An explicit choice from the form always wins.
     */
    /**
     * Who hands this document over, and who takes it.
     *
     * Public, and used by the generate form as well as by buildPayload, because the
     * form and the PDF must not each decide this separately. They did once: the view
     * re-implemented the same condition, and when the condition turned out to be
     * wrong, the form and the generated document disagreed about who was signing.
     *
     * The three shapes, from config('documents.types'):
     *
     *   issue    - FAT, BAST, ASSIGNMENT. IT hands a laptop to an employee. Nobody
     *              is giving anything back, so the sender is the IT PIC.
     *   return   - RETURN. The employee hands it back and IT receives it.
     *   transfer - TRANSFER, or any document raised from a saved transfer. It moves
     *              between two named people, and the transfer already knows who.
     *
     * @return array{sender: ?User, receiver: ?User}
     */
    public function partiesFor(Asset $asset, string $type, ?AssetTransfer $transfer = null): array
    {
        $itPic = $asset->adminItPic;

        // Read from the assignment history, not from assets.current_user_id. The two
        // can disagree when a laptop has moved and nobody updated the denormalised
        // column, and the history is the record the document is a record of.
        $first = $asset->firstHolder();
        $current = $asset->currentHolder();

        if ($transfer !== null) {
            return [
                'sender' => $transfer->fromUser ?? $current?->user ?? $first?->user,
                'receiver' => $transfer->toUser ?? $asset->currentUser ?? $first?->user,
            ];
        }

        if ($type === 'RETURN') {
            return [
                'sender' => $current?->user ?? $first?->user,
                'receiver' => $itPic,
            ];
        }

        return [
            'sender' => $itPic,
            'receiver' => $asset->currentUser ?? $first?->user,
        ];
    }

    private function partiesForPayload(Asset $asset, array $input, ?AssetTransfer $transfer, string $type): array
    {
        ['sender' => $senderUser, 'receiver' => $receiverUser] = $this->partiesFor($asset, $type, $transfer);

        // The transfer's own wording wins where it has one: to_department is the
        // destination's department as it was written at the time, which beats
        // whatever the user's profile says today.
        $division = $transfer?->to_department ?? $receiverUser?->department;

        return [
            'sender' => [
                // The form has no separate "name" field for the parties: the people
                // are named in the signature block, and a second name box would be a
                // second thing to keep in step with it. An explicit choice from the
                // form always wins over the derived value, because the document is
                // signed by people and a proxy has to be able to say so.
                'name' => $input['signatory_menyerahkan'] ?? $senderUser?->name,
                'employee_id' => $input['sender_employee_id'] ?? $senderUser?->employee_id,
                'entity' => $input['sender_entity'] ?? $asset->company?->name,
                'division' => $input['sender_division'] ?? $senderUser?->department,
                'email' => $input['sender_email'] ?? $senderUser?->email,
                'phone' => $input['sender_phone'] ?? null,
            ],
            'receiver' => [
                'name' => $input['signatory_menerima'] ?? $receiverUser?->name,
                'employee_id' => $input['receiver_employee_id'] ?? $receiverUser?->employee_id,
                // The register knows a location but not a legal entity, so only a
                // transfer can supply one; otherwise the asset's own company is a
                // better guess than a blank on a signed document. Location is a
                // separate field now that "Company / Jakarta" is no longer mashed
                // into one string.
                'entity' => $input['receiver_entity'] ?? $transfer?->toLocation?->name ?? $asset->company?->name,
                'location' => $input['receiver_location'] ?? $transfer?->toLocation?->name ?? $asset->location?->name,
                'division' => $input['receiver_division'] ?? $division,
                'email' => $input['receiver_email'] ?? $receiverUser?->email,
                'phone' => $input['receiver_phone'] ?? null,
            ],
        ];
    }

    private function renderPdf(string $type, array $payload, string $number, array $input): string
    {
        return Pdf::loadView($this->viewFor($type), [
            'document' => $payload + ['number' => $number],
        ])->setPaper('a4')->output();
    }

    private function viewFor(string $type): string
    {
        return match ($type) {
            'FAT' => 'documents.pdf.fat',
            'BAST' => 'documents.pdf.bast',
            default => 'documents.pdf.generic',
        };
    }

    private function titleFor(string $type, Asset $asset): string
    {
        $label = config('documents.labels.'.$type, $type);

        return "{$label} — {$asset->asset_id}";
    }

    /**
     * Acceptance checklist answers, keyed by item index; unknown answers become N/A.
     *
     * Per type rather than always-FAT: a TRANSFER has no acceptance checklist, and
     * storing the FAT items against one would print them onto a document that
     * never had them.
     */
    private function checklistFor(string $type, array $input): array
    {
        $defaults = (array) config('documents.checklists.'.$type, []);
        $answers = (array) ($input['checklist'] ?? []);

        $result = [];

        foreach ($defaults as $index => $item) {
            $result[] = [
                'item' => $item,
                'result' => in_array($answers[$index] ?? null, ['pass', 'fail', 'na'], true)
                    ? $answers[$index]
                    : 'na',
            ];
        }

        return $result;
    }
}
