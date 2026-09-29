<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Document;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
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

        return DB::transaction(function () use ($asset, $type, $payload, $transfer, $actor) {
            $number = $this->numbers->next($type);
            $fileName = Str::slug($number).'.pdf';
            $relativePath = trim((string) config('documents.storage_path', 'documents'), '/').'/'.$fileName;

            Storage::put($relativePath, $this->renderPdf($type, $payload, $number));

            $document = Document::create([
                'asset_id' => $asset->id,
                'transfer_id' => $transfer?->id,
                'document_type' => $type,
                'document_number' => $number,
                'title' => $payload['title'],
                'template_id' => $payload['template_id'],
                'payload' => $payload,
                'file_path' => $relativePath,
                'file_type' => 'pdf',
                'generated_date' => now()->toDateString(),
                'generated_by' => $actor?->id ?? Auth::id(),
                'status' => 'final',
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
        return [
            'title' => $this->titleFor($type, $asset),
            'template_id' => $input['template_id'] ?? null,
            'date' => $input['document_date'] ?? now()->toDateString(),
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
                'os_version' => $asset->os_version,
                'status' => $asset->status?->name,
                'type' => $input['asset_type'] ?? $asset->category?->name,
                'brand_model' => $input['asset_brand_model'] ?? trim(($asset->brand ?? '').' '.($asset->model ?? '')),
                'serial' => $input['asset_serial_number'] ?? $asset->serial_number,
                'tag' => $input['asset_tag'] ?? $asset->asset_id,
                'processor_detail' => $input['asset_processor'] ?? $asset->processor,
                'ram_detail' => $input['asset_ram'] ?? $asset->ram,
                'storage_detail' => $input['asset_storage'] ?? $asset->storage,
                'accessories' => $input['asset_accessories'] ?? null,
            ],
            'company' => $asset->company?->name,
            'location' => $asset->location?->name,
            'current_user' => $asset->currentUser?->name,
            'parties' => $this->partiesFor($asset, $input, $transfer),
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
                'date' => $input['handover_date'] ?? $input['document_date'] ?? now()->toDateString(),
                'received_date' => $input['received_date'] ?? null,
                'purpose' => $input['purpose'] ?? null,
            ],
            'signatories' => [
                'menyerahkan' => $input['signatory_menyerahkan'] ?? null,
                'menerima' => $input['signatory_menerima'] ?? null,
                'mengetahui' => $input['signatory_mengetahui'] ?? null,
                'it' => $input['signatory_it'] ?? null,
            ],
            'checklist' => $this->checklistFor($type, $input),
            'notes' => $input['notes'] ?? null,
            'statement' => $input['statement'] ?? null,
        ];
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
    private function partiesFor(Asset $asset, array $input, ?AssetTransfer $transfer): array
    {
        return [
            'sender' => [
                // The form has no separate "name" field for the parties: the
                // people are named in the signature block, and a second name box
                // would be a second thing to keep in step with it.
                'name' => $input['signatory_menyerahkan'] ?? $asset->currentUser?->name,
                'employee_id' => $input['sender_employee_id'] ?? null,
                'entity' => $input['sender_entity'] ?? $asset->company?->name,
                'division' => $input['sender_division'] ?? $asset->currentUser?->department,
                'email' => $input['sender_email'] ?? $asset->currentUser?->email,
                'phone' => $input['sender_phone'] ?? null,
            ],
            'receiver' => [
                'name' => $input['signatory_menerima'] ?? $transfer?->toUser?->name,
                'employee_id' => $input['receiver_employee_id'] ?? null,
                // The register knows a location but not a legal entity, so only a
                // transfer can supply one; otherwise the asset's own company is a
                // better guess than a blank on a signed document. Location is a
                // separate field now that "Company / Jakarta" is no longer mashed
                // into one string.
                'entity' => $input['receiver_entity'] ?? $transfer?->toLocation?->name ?? $asset->company?->name,
                'location' => $input['receiver_location'] ?? $transfer?->toLocation?->name ?? $asset->location?->name,
                'division' => $input['receiver_division'] ?? $transfer?->to_department,
                'email' => $input['receiver_email'] ?? null,
                'phone' => $input['receiver_phone'] ?? null,
            ],
        ];
    }

    private function renderPdf(string $type, array $payload, string $number): string
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
        $label = match ($type) {
            'FAT' => 'Form Acceptance Test',
            'BAST' => 'Berita Acara Serah Terima',
            'TRANSFER' => 'Asset Transfer',
            'RETURN' => 'Asset Return',
            'ASSIGNMENT' => 'Asset Assignment',
            default => $type,
        };

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
