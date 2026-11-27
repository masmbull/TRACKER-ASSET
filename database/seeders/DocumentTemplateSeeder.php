<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Canonical (pakem) FAT & BAST templates, mapped field-by-field from the official
 * Excel form "Template Form Fixed Transfer Asset IT v1 (2026).xlsx":
 *  - FAT  <- sheets SERAH / TERIMA (Form Transfer Fixed Asset IT, sections A-F + checklist)
 *  - BAST <- sheet "Template BAST - BARU" (sections A-F)
 *
 * template_content follows the documented flat schema:
 *   { "field_key": { "label": ..., "type": ..., "section": ... } }
 */
class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $createdBy = User::query()->where('role', 'admin')->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if (! $createdBy) {
            $this->command?->warn('DocumentTemplateSeeder skipped: no users yet (run UserSeeder first).');

            return;
        }

        DocumentTemplate::updateOrCreate(
            ['name' => 'FAT Pakem — Form Transfer Fixed Asset IT v1'],
            [
                'document_type' => 'FAT',
                'template_content' => $this->fatFields(),
                'is_active' => true,
                'created_by' => $createdBy,
            ]
        );

        DocumentTemplate::updateOrCreate(
            ['name' => 'BAST Pakem — Berita Acara Serah Terima Aset IT (BARU)'],
            [
                'document_type' => 'BAST',
                'template_content' => $this->bastFields(),
                'is_active' => true,
                'created_by' => $createdBy,
            ]
        );

        // The wider document library follows the same field schema. Each type
        // gets a canonical template so the Templates page and the generation
        // form have a real definition to point at, not just FAT/BAST.
        foreach (['DISPOSAL', 'REPAIR'] as $type) {
            $label = config('documents.labels.'.$type, $type);

            DocumentTemplate::updateOrCreate(
                ['name' => $label.' — Pakem'],
                [
                    'document_type' => $type,
                    'template_content' => $this->standardFields($type),
                    'is_active' => true,
                    'created_by' => $createdBy,
                ]
            );
        }

        // updateOrCreate cannot remove rows from an older seed. Without this the
        // Templates page keeps offering TRANSFER/HANDOVER/RETURN/ASSIGNMENT long
        // after those document types were consolidated into FAT.
        DocumentTemplate::whereIn('document_type', ['TRANSFER', 'HANDOVER', 'RETURN', 'ASSIGNMENT'])->delete();

        $this->command?->info('FAT, BAST, DISPOSAL and REPAIR templates seeded.');
    }

    /**
     * FAT = transfer form (sheets SERAH/TERIMA) sections A-F, plus the FAT checklist.
     */
    private function fatFields(): array
    {
        $f = static fn (string $label, string $type, string $section): array => [
            'label' => $label,
            'type' => $type,
            'section' => $section,
        ];

        $a = 'A. Informasi Yang Menyerahkan';
        $b = 'B. Informasi Yang Menerima';
        $c = 'C. Informasi Aset';
        $spec = 'C. Informasi Aset — Spesifikasi';
        $kel = 'C. Informasi Aset — Kelengkapan Asset';
        $d = 'D. Waktu Penyerahan';
        $e = 'E. Pernyataan';
        $g = 'F. Persetujuan';

        $fields = [
            'sender_name' => $f('Nama Penyerah', 'text', $a),
            'sender_employee_id' => $f('NIK / ID Karyawan', 'text', $a),
            'sender_entity' => $f('Entitas', 'text', $a),
            'sender_division' => $f('Divisi / Jabatan', 'text', $a),
            'sender_email' => $f('Email', 'email', $a),
            'sender_phone' => $f('No. Telepon', 'tel', $a),

            'receiver_name' => $f('Nama Penerima', 'text', $b),
            'receiver_nik' => $f('NIK / ID Karyawan', 'text', $b),
            'receiver_entity' => $f('Entitas', 'text', $b),
            'receiver_division' => $f('Divisi / Jabatan', 'text', $b),
            'receiver_email' => $f('Email', 'email', $b),
            'receiver_phone' => $f('No. Telepon', 'tel', $b),

            'asset_type' => $f('Jenis Aset', 'text', $c),
            'asset_brand_model' => $f('Brand & Model', 'text', $c),
            'asset_serial_number' => $f('Serial Number', 'text', $c),
            'asset_tag' => $f('Asset Tag / Inventory Code', 'text', $c),
            'asset_processor' => $f('Processor', 'text', $spec),
            'asset_ram' => $f('RAM', 'text', $spec),
            'asset_storage' => $f('Storage', 'text', $spec),
            'asset_notes' => $f('Catatan', 'textarea', $spec),

            'kelengkapan_laptop' => $f('Laptop/PC', 'ada_tidak', $kel),
            'kelengkapan_charger' => $f('Charger', 'ada_tidak', $kel),
            'kelengkapan_mouse' => $f('Mouse', 'ada_tidak', $kel),
            'kelengkapan_tas' => $f('Tas', 'ada_tidak', $kel),
            'kelengkapan_accessories' => $f('Accessories', 'ada_tidak', $kel),
            'kelengkapan_detail' => $f('Jika ada, sebutkan', 'text', $kel),

            'handover_date' => $f('Tanggal Penyerahan', 'date', $d),
            'received_date' => $f('Tanggal Diterima', 'date', $d),
            'purpose' => $f('Keperluan', 'text', $d),
            'notes' => $f('Catatan', 'textarea', $d),

            'statement' => $f(
                'Digunakan untuk keperluan pekerjaan dan jika akan dikembalikan harus dalam kondisi baik seperti diterima.',
                'textarea',
                $e
            ),

            'signatory_menerima' => $f('PENERIMA — Nama', 'text', $g),
            'signatory_menerima_date' => $f('PENERIMA — Tanggal', 'date', $g),
            'signatory_menyerahkan' => $f('PENYERAH — Nama', 'text', $g),
            'signatory_menyerahkan_date' => $f('PENYERAH — Tanggal', 'date', $g),
        ];

        foreach ((array) config('documents.checklists.FAT', []) as $index => $item) {
            $fields['checklist_'.$index] = $f($item, 'pass_fail_na', 'Checklist FAT');
        }

        return $fields;
    }

    /**
     * BAST = sheet "Template BAST - BARU" sections A-F.
     */
    private function bastFields(): array
    {
        $f = static fn (string $label, string $type, string $section): array => [
            'label' => $label,
            'type' => $type,
            'section' => $section,
        ];

        $a = 'A. Informasi Penerima Aset';
        $b = 'B. Informasi Penyerah Aset (IT)';
        $c = 'C. Informasi Aset';
        $d = 'D. Kelengkapan Aset';
        $e = 'E. Waktu Penyerahan';
        $g = 'F. Klausul';
        $sig = 'Tanda Tangan';

        return [
            'receiver_name' => $f('Nama Karyawan', 'text', $a),
            'receiver_nik' => $f('NIK / ID Karyawan', 'text', $a),
            'receiver_division' => $f('Divisi / Jabatan', 'text', $a),
            'receiver_entity' => $f('Entitas / Cabang', 'text', $a),
            'receiver_email' => $f('Email', 'email', $a),
            'receiver_phone' => $f('No. Telepon', 'tel', $a),

            'it_name' => $f('Nama PIC IT', 'text', $b),
            'it_nik' => $f('NIK / ID Karyawan', 'text', $b),
            'it_division' => $f('Divisi / Jabatan', 'text', $b),
            'it_email' => $f('Email', 'email', $b),
            'it_phone' => $f('No. Telepon', 'tel', $b),

            'asset_type' => $f('Jenis Aset', 'text', $c),
            'asset_brand_model' => $f('Brand & Model', 'text', $c),
            'asset_serial_number' => $f('Serial Number', 'text', $c),
            'asset_tag' => $f('Asset Tag / Inventory Code', 'text', $c),
            'asset_processor' => $f('Processor', 'text', $c),
            'asset_ram' => $f('RAM', 'text', $c),
            'asset_storage' => $f('Storage', 'text', $c),
            'asset_os' => $f('Sistem Operasi', 'text', $c),
            'asset_condition' => $f('Kondisi Aset', 'text', $c),
            'asset_notes' => $f('Catatan Tambahan', 'textarea', $c),

            'kelengkapan_laptop' => $f('Laptop', 'ada_tidak', $d),
            'kelengkapan_charger' => $f('Charger', 'ada_tidak', $d),
            'kelengkapan_mouse' => $f('Mouse', 'ada_tidak', $d),
            'kelengkapan_tas' => $f('Tas Laptop', 'ada_tidak', $d),
            'kelengkapan_keyboard' => $f('Keyboard External', 'ada_tidak', $d),
            'kelengkapan_accessories' => $f('Accessories Lain', 'ada_tidak', $d),

            'handover_date' => $f('Tanggal Penyerahan', 'date', $e),
            'purpose' => $f('Keperluan', 'text', $e),
            'handover_location' => $f('Lokasi Penyerahan', 'text', $e),
            'notes' => $f('Catatan', 'textarea', $e),

            'statement' => $f('Klausul / Pernyataan Serah Terima', 'textarea', $g),

            'signatory_menerima' => $f('Penerima Aset — Nama', 'text', $sig),
            'signatory_menerima_date' => $f('Penerima Aset — Tanggal', 'date', $sig),
            'signatory_it' => $f('Penyerah Aset (IT) — Nama', 'text', $sig),
            'signatory_it_date' => $f('Penyerah Aset (IT) — Tanggal', 'date', $sig),
        ];
    }

    /**
     * Canonical fields for the non-office-sheet document types. Sections follow
     * the same A-F shape so an operator can move between document types without
     * learning a different form, while the labels carry the type's own wording.
     */
    private function standardFields(string $type): array
    {
        $f = static fn (string $label, string $fieldType, string $section): array => [
            'label' => $label,
            'type' => $fieldType,
            'section' => $section,
        ];

        $a = 'A. Informasi Pihak Pertama';
        $b = 'B. Informasi Pihak Kedua';
        $c = 'C. Informasi Aset';
        $d = 'D. Kelengkapan Aset';
        $e = 'E. Waktu';
        $g = 'F. Pernyataan / Tanda Tangan';

        $fields = [
            'sender_name' => $f('Nama Pihak Pertama', 'text', $a),
            'sender_employee_id' => $f('NIK / ID', 'text', $a),
            'sender_entity' => $f('Entitas', 'text', $a),
            'sender_division' => $f('Divisi / Jabatan', 'text', $a),
            'sender_email' => $f('Email', 'email', $a),
            'sender_phone' => $f('No. Telepon', 'tel', $a),

            'receiver_name' => $f('Nama Pihak Kedua', 'text', $b),
            'receiver_employee_id' => $f('NIK / ID', 'text', $b),
            'receiver_entity' => $f('Entitas', 'text', $b),
            'receiver_division' => $f('Divisi / Jabatan', 'text', $b),
            'receiver_email' => $f('Email', 'email', $b),
            'receiver_phone' => $f('No. Telepon', 'tel', $b),

            'asset_type' => $f('Jenis Aset', 'text', $c),
            'asset_brand_model' => $f('Brand & Model', 'text', $c),
            'asset_serial_number' => $f('Serial Number', 'text', $c),
            'asset_tag' => $f('Asset Tag / Inventory Code', 'text', $c),
            'asset_processor' => $f('Processor', 'text', $c),
            'asset_ram' => $f('RAM', 'text', $c),
            'asset_storage' => $f('Storage', 'text', $c),
            'asset_os_version' => $f('Sistem Operasi', 'text', $c),
            'notes' => $f('Catatan', 'textarea', $c),
        ];

        foreach ((array) config('documents.kelengkapan.'.$type, []) as $index => $item) {
            $fields['kelengkapan_'.$index] = $f($item, 'ada_tidak', $d);
        }

        $fields['handover_date'] = $f(config('documents.copy.'.$type.'.handover_date', 'Tanggal Penyerahan'), 'date', $e);
        $fields['received_date'] = $f(config('documents.copy.'.$type.'.received_date', 'Tanggal Diterima'), 'date', $e);
        $fields['purpose'] = $f('Keperluan', 'text', $e);
        $fields['statement'] = $f('Pernyataan / Klausul', 'textarea', $g);
        $fields['signatory_menerima'] = $f('Penerima — Nama', 'text', $g);
        $fields['signatory_menerima_date'] = $f('Penerima — Tanggal', 'date', $g);
        $fields['signatory_menyerahkan'] = $f('Penyerah — Nama', 'text', $g);
        $fields['signatory_menyerahkan_date'] = $f('Penyerah — Tanggal', 'date', $g);
        $fields['signatory_it'] = $f('IT — Nama', 'text', $g);
        $fields['signatory_it_date'] = $f('IT — Tanggal', 'date', $g);

        foreach ((array) config('documents.checklists.'.$type, []) as $index => $item) {
            $fields['checklist_'.$index] = $f($item, 'pass_fail_na', 'Checklist '.strtoupper($type));
        }

        return $fields;
    }
}
