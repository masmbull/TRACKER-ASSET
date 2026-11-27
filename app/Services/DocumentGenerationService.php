<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Document;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates FAT/BAST/etc. documents from asset and transfer data (PRD §32–§35).
 *
 * Register-backed and manually entered fields are persisted so a document can
 * be reproduced from the saved payload.
 */
class DocumentGenerationService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * Create the document record plus its PDF, atomically.
     */
    public function generate(?Asset $asset, string $type, array $input, ?User $actor = null): Document
    {
        $type = Str::upper(trim($type));

        abort_unless(in_array($type, (array) config('documents.types', []), true), 422, "Unsupported document type: {$type}");

        $asset?->loadMissing(['company', 'category', 'location', 'status', 'currentUser']);

        $transfer = null;

        if ($asset && ! empty($input['transfer_id'])) {
            $transfer = $asset->transfers()->find((int) $input['transfer_id']);
        }

        $payload = $this->buildPayload($asset, $type, $input, $transfer);

        return DB::transaction(function () use ($asset, $type, $payload, $transfer, $actor, $input) {
            $number = $this->numbers->next($type);
            $fileName = Str::slug($number).'.pdf';
            $relativePath = trim((string) config('documents.storage_path', 'documents'), '/').'/'.$fileName;

            $signaturePath = null;
            $signatureImageData = null;
            $receiverSignatureData = null;
            $receiverSignaturePath = null;

            // Two signatories, two images. The IT signature stays in the
            // document's `signature_image` column for backwards compatibility;
            // the receiver's is kept in the payload so older documents still
            // render exactly as they did before this field existed.
            foreach ([
                ['signatory_image', &$signaturePath, &$signatureImageData, 'it'],
                ['signatory_receiver_image', &$receiverSignaturePath, &$receiverSignatureData, 'penerima'],
            ] as [$field, &$pathRef, &$dataRef, $role]) {
                if (($input[$field] ?? null) instanceof UploadedFile) {
                    $image = $input[$field];
                    $pathRef = 'signatures/'.Str::slug($number).'-'.$role.'-'.Str::random(8).'.'.$image->extension();

                    if (! Storage::put($pathRef, $image)) {
                        throw new \RuntimeException('Unable to store the uploaded signature image.');
                    }

                    $dataRef = 'data:'.$image->getMimeType().';base64,'.base64_encode($image->get());
                }
            }

            $payload['signature_image_data'] = $signatureImageData;
            $payload['receiver_signature_image_data'] = $receiverSignatureData;

            if (! Storage::put($relativePath, $this->renderPdf($type, $payload, $number, $signatureImageData))) {
                throw new \RuntimeException('Unable to store the generated document.');
            }

            $document = Document::create([
                'asset_id' => $asset?->id,
                'transfer_id' => $transfer?->id,
                'document_type' => $type,
                'document_number' => $number,
                'nomor_surat' => $number,
                'title' => $payload['title'],
                'template_id' => $payload['template_id'],
                'payload' => $payload + [
                    'signature_image' => $signaturePath,
                    'receiver_signature_image' => $receiverSignaturePath,
                    'nomor_surat' => $number,
                ],
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
                'new_value' => json_encode([
                    'type' => $type,
                    'asset' => $asset?->asset_id ?? ($input['asset_tag'] ?? null),
                ]),
                'source' => 'Document',
            ]);

            return $document;
        });
    }

    /**
     * Edit an already-generated document and overwrite its PDF in place.
     *
     * The number is deliberately untouched: the document already exists and its
     * sequence must not advance just because somebody fixed a name on it.
     */
    public function regenerate(Document $document, array $input, ?User $actor = null): Document
    {
        $type = strtoupper((string) ($input['document_type'] ?? $document->document_type));
        $payload = (array) ($document->payload ?? []);

        if (filled($input['document_date'] ?? null)) {
            $payload['date'] = $input['document_date'];
            $payload['date_long'] = $this->longDate($input['document_date']);
        }
        if (filled($input['nomor_surat'] ?? null)) {
            $payload['nomor_surat'] = $input['nomor_surat'];
        }

        foreach (['title', 'notes', 'statement', 'location'] as $key) {
            if (array_key_exists($key, $input)) {
                $payload[$key] = $input[$key];
            }
        }

        $parties = $payload['parties'] ?? [];
        foreach (['sender' => 'menyerahkan', 'receiver' => 'menerima'] as $side => $signatoryKey) {
            $party = (array) ($parties[$side] ?? []);
            $nameKey = $side === 'sender' ? 'signatory_menyerahkan' : 'signatory_menerima';

            if (filled($input[$nameKey] ?? null)) {
                $party['name'] = $input[$nameKey];
            }
            foreach (['employee_id', 'entity', 'division', 'email', 'phone'] as $field) {
                $inputKey = "{$side}_{$field}";
                if (array_key_exists($inputKey, $input)) {
                    $party[$field] = $input[$inputKey];
                }
            }
            $parties[$side] = $party;
        }
        $payload['parties'] = $parties;

        $signatories = (array) ($payload['signatories'] ?? []);
        foreach (['menyerahkan', 'menerima', 'mengetahui', 'it'] as $role) {
            if (filled($input["signatory_{$role}"] ?? null)) {
                $signatories[$role] = $input["signatory_{$role}"];
            }
            if (filled($input["signatory_{$role}_date"] ?? null)) {
                $signatories["{$role}_date"] = $this->longDate($input["signatory_{$role}_date"]);
            }
        }
        $payload['signatories'] = $signatories;

        $handover = (array) ($payload['handover'] ?? []);
        foreach (['handover_date' => 'date', 'received_date' => 'received_date'] as $inputKey => $key) {
            if (filled($input[$inputKey] ?? null)) {
                $handover[$key] = $input[$inputKey];
                $handover[$key === 'date' ? 'date_long' : 'received_long'] = $this->longDate($input[$inputKey]);
            }
        }
        if (array_key_exists('purpose', $input)) {
            $handover['purpose'] = $input['purpose'];
        }
        $payload['handover'] = $handover;

        $payload['checklist'] = $this->checklistFor($type, $input);
        $payload['kelengkapan'] = $this->kelengkapanFor($type, $input);

        // A blank document has no register to fall back to, so its asset block
        // lives entirely in the payload and must be editable there.
        $assetData = (array) ($payload['asset'] ?? []);
        foreach (['type', 'brand_model', 'serial', 'tag', 'processor', 'ram', 'storage', 'os_version'] as $field) {
            $inputKey = 'asset_'.$field;
            if (array_key_exists($inputKey, $input)) {
                $assetData[$field === 'os_version' ? 'os_version' : ($field === 'type' ? 'type' : $field)] = $input[$inputKey];
                if ($field === 'processor') {
                    $assetData['processor_detail'] = $input[$inputKey];
                } elseif ($field === 'ram') {
                    $assetData['ram_detail'] = $input[$inputKey];
                } elseif ($field === 'storage') {
                    $assetData['storage_detail'] = $input[$inputKey];
                } elseif ($field === 'tag') {
                    $assetData['tag'] = $input[$inputKey];
                }
            }
        }
        $payload['asset'] = $assetData;

        foreach ([
            ['signatory_image', 'signature_image', 'signature_image_data'],
            ['signatory_receiver_image', 'receiver_signature_image', 'receiver_signature_image_data'],
        ] as [$field, $pathKey, $dataKey]) {
            $image = $input[$field] ?? null;
            if (! $image instanceof UploadedFile) {
                continue;
            }
            $path = 'signatures/'.Str::slug($document->document_number).'-'.$pathKey.'-'.Str::random(8).'.'.$image->extension();
            Storage::put($path, $image);
            $payload[$pathKey] = $path;
            $payload[$dataKey] = 'data:'.$image->getMimeType().';base64,'.base64_encode($image->get());
            if ($pathKey === 'signature_image') {
                $document->signature_image = $path;
            }
        }

        $pdf = $this->renderPdf($type, $payload, $document->document_number, $payload['signature_image_data'] ?? null);
        abort_unless(Storage::put($document->file_path, $pdf), 500, 'Could not write the regenerated PDF.');

        $document->document_type = $type;
        $document->payload = $payload;
        $document->title = $payload['title'] ?? $document->title;
        $document->template_id = $input['template_id'] ?? $document->template_id;
        $document->nomor_surat = $payload['nomor_surat'] ?? null;
        $document->generated_by = $actor?->id ?? Auth::id();
        $document->save();

        ActivityLog::create([
            'actor_id' => $actor?->id ?? Auth::id(),
            'action' => 'UPDATE_DOCUMENT',
            'entity_type' => 'documents',
            'entity_id' => $document->id,
            'entity_identifier' => $document->document_number,
            'source' => 'Document',
        ]);

        return $document;
    }

    /**
     * Everything the document needs, filled from the register or form input.
     */
    public function buildPayload(?Asset $asset, string $type, array $input, ?AssetTransfer $transfer): array
    {
        // Dates are rendered in Indonesian on the face of the document. The paper
        // form writes "14 September 2026"; the PDF used to print the raw ISO
        // string ("2026-09-14") from the column, which is a storage format, not
        // something to sign. Formatted once here so every template reads the same.
        $handoverDate = $input['handover_date'] ?? $input['document_date'] ?? now()->toDateString();
        $receivedDate = $input['received_date'] ?? null;

        // Resolved once: the signature block falls back to the same people the
        // rest of the document uses, so an untouched form still prints real
        // names instead of a bare "-".
        $parties = $this->partiesForPayload($asset, $input, $transfer, $type);

        return [
            'type' => $type,
            'title' => $this->titleFor($type, $asset, $input),
            'template_id' => $input['template_id'] ?? null,
            'date' => $input['document_date'] ?? now()->toDateString(),
            'date_long' => $this->longDate($input['document_date'] ?? now()->toDateString()),
            'asset' => [
                'asset_id' => $input['asset_tag'] ?? $asset?->asset_id,
                'category' => $asset?->category?->name,
                'brand' => $asset?->brand,
                'model' => $asset?->model,
                'serial_number' => $asset?->serial_number,
                'hostname' => $asset?->hostname,
                'processor' => $asset?->processor,
                'ram' => $asset?->ram,
                'storage' => $asset?->storage,
                'storage_type' => $input['asset_storage_type'] ?? $asset?->storage_type,
                'storage_capacity' => $input['asset_storage_capacity'] ?? $asset?->storage_capacity,
                'os_version' => $input['asset_os_version'] ?? $asset?->os_version,
                'status' => $asset?->status?->name,
                'type' => $input['asset_type'] ?? $asset?->category?->name,
                'brand_model' => $input['asset_brand_model'] ?? trim(($asset?->brand ?? '').' '.($asset?->model ?? '')),
                'serial' => $input['asset_serial_number'] ?? $asset?->serial_number,
                'tag' => $input['asset_tag'] ?? $asset?->asset_id,
                'processor_detail' => $input['asset_processor'] ?? $asset?->processor,
                'ram_detail' => $input['asset_ram'] ?? $asset?->ram,
                'storage_detail' => $input['asset_storage'] ?? $asset?->storage,
                'storage_type_detail' => $input['asset_storage_type'] ?? $asset?->storage_type,
                'storage_capacity_detail' => $input['asset_storage_capacity'] ?? $asset?->storage_capacity,
                'accessories' => $input['asset_accessories'] ?? null,
            ],
            'company' => $asset?->company?->name,
            'company_logo' => $asset?->company?->logo,
            'location' => $input['receiver_location'] ?? $transfer?->toLocation?->name ?? $asset?->location?->name,
            'current_user' => $asset?->currentUser?->name,
            'parties' => $parties,
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
                'menerima' => filled($input['signatory_menerima'] ?? null) ? $input['signatory_menerima'] : ($parties['receiver']['name'] ?? null),
                'mengetahui' => $input['signatory_mengetahui'] ?? null,
                // The signature box is the IT/Penyerah box. Its name must follow
                // "Nama Penyerah" from section A - that is the field people edit.
                // "IT / Nama Karyawan" in section F is an override for the case
                // where the person signing is not the person handing over, and
                // is deliberately left blank by default so it cannot shadow the
                // name they just typed.
                'it' => filled($input['signatory_it'] ?? null)
                    ? $input['signatory_it']
                    : ($input['signatory_menyerahkan'] ?? ($parties['sender']['name'] ?? null)),
                'menyerahkan_date' => $this->longDate($input['signatory_menyerahkan_date'] ?? null),
                'menerima_date' => $this->longDate($input['signatory_menerima_date'] ?? null),
                'it_date' => $this->longDate($input['signatory_it_date'] ?? null),
            ],
            'checklist' => $this->checklistFor($type, $input),
            // Completeness answers keyed by the item label, so the document prints
            // "Charger: Ada" and not a bare list of keys.
            'kelengkapan' => $this->kelengkapanFor($type, $input),
            'notes' => $input['notes'] ?? null,
            'statement' => $input['statement'] ?? $this->statementFor($type),
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
    public function partiesFor(?Asset $asset, string $type, ?AssetTransfer $transfer = null): array
    {
        if (! $asset) {
            return ['sender' => null, 'receiver' => null];
        }

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

    private function partiesForPayload(?Asset $asset, array $input, ?AssetTransfer $transfer, string $type): array
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
                'entity' => $input['sender_entity'] ?? $asset?->company?->name,
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
                'entity' => $input['receiver_entity'] ?? $transfer?->toLocation?->name ?? $asset?->company?->name,
                'location' => $input['receiver_location'] ?? $transfer?->toLocation?->name ?? $asset?->location?->name,
                'division' => $input['receiver_division'] ?? $division,
                'email' => $input['receiver_email'] ?? $receiverUser?->email,
                'phone' => $input['receiver_phone'] ?? null,
            ],
        ];
    }

    private function renderPdf(string $type, array $payload, string $number, ?string $signatureImageData): string
    {
        // Copy (date labels, statement) is type-specific and must reach the PDF;
        // it used to be read in the Blade but never passed, so RETURN and
        // ASSIGNMENT documents silently printed FAT wording.
        $copy = array_merge(
            (array) config('documents.copy.FAT', []),
            (array) config('documents.copy.'.$type, [])
        );

        return Pdf::loadView($this->viewFor($type), [
            'document' => $payload + [
                'number' => $number,
                'nomor_surat' => $payload['nomor_surat'] ?? $number,
                'signature_image_data' => $signatureImageData,
                'copy' => $copy,
            ],
        ])->setPaper('a4')->output();
    }

    private function viewFor(string $type): string
    {
        return match ($type) {
            'BAST' => 'documents.pdf.bast',
            'REPAIR' => 'documents.pdf.repair',
            'DISPOSAL' => 'documents.pdf.disposal',
            // FAT covers transfer, handover and return; a legacy document
            // recorded under one of those retired types still renders the same
            // sheet instead of naming a document type the office no longer uses.
            default => 'documents.pdf.fat',
        };
    }

    private function titleFor(string $type, ?Asset $asset, array $input): string
    {
        $label = config('documents.labels.'.$type, $type);
        $assetTag = $input['asset_tag'] ?? $asset?->asset_id;

        return filled($assetTag) ? "{$label} — {$assetTag}" : $label;
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
