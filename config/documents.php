<?php

/**
 * Document generation configuration (PRD §32, §33, §34, §35).
 */
return [

    // Document types that can be generated automatically.
    'types' => ['FAT', 'BAST', 'TRANSFER', 'RETURN', 'ASSIGNMENT'],

    /*
    | Numbering format per type. Tokens: {year}, {seq:N} (N-digit, zero-padded).
    | Sequences are per document type and per year, and are never reused.
    | The unique index on documents.document_number is the final safety net.
    */
    'numbering' => [
        'FAT' => 'FAT/IT/{year}/{seq:5}',
        'BAST' => 'BAST/IT/{year}/{seq:5}',
        'TRANSFER' => 'AST/TRF/{year}/{seq:5}',
        'RETURN' => 'AST/RET/{year}/{seq:5}',
        'ASSIGNMENT' => 'AST/ASG/{year}/{seq:5}',
    ],

    /*
    | Acceptance checklists, keyed by document type.
    |
    | Keyed rather than one flat `fat_checklist`, because only some document types
    | have one. The form used to render the FAT checklist for every type -
    | including BAST and TRANSFER, which do not have it - and a section headed
    | "Checklist FAT" on a transfer document is worse than no section at all. An
    | empty list means the section is not rendered and nothing is stored.
    */
    'checklists' => [
        'FAT' => [
            'Perangkat menyala dan boot normal',
            'Kondisi fisik baik, tidak ada kerusakan',
            'Port fisik (USB/HDMI/LAN) berfungsi',
            'Baterai & charger berfungsi (jika ada)',
            'Processor/RAM/Storage sesuai data terdaftar',
            'OS terinstal dan berjalan normal',
            'Aksesoris lengkap sesuai catatan serah terima',
        ],
    ],

    /*
    | Wording that legitimately differs per document type. Anything not listed
    | falls back to the FAT copy, which is the handover it was written for.
    |
    | Dates are the clearest case: a return is not a "serah terima" and an
    | assignment is not a "transfer", and these labels are carried verbatim onto
    | a document people sign.
    */
    'copy' => [
        'FAT' => [
            'handover_date' => 'Tanggal Serah Terima',
            'received_date' => 'Tanggal Diterima',
            'statement' => 'Digunakan untuk keperluan pekerjaan dan jika akan dikembalikan harus dalam kondisi baik seperti diterima.',
        ],
        'RETURN' => [
            'handover_date' => 'Tanggal Pengembalian',
            'received_date' => 'Tanggal Diterima Kembali',
            'statement' => 'Dikembalikan dalam kondisi baik seperti saat diterima.',
        ],
        'ASSIGNMENT' => [
            'handover_date' => 'Tanggal Penyerahan',
            'received_date' => 'Tanggal Diterima',
            'statement' => 'Diserahkan untuk dipergunakan dalam pelaksanaan tugas dan wajib dikembalikan saat tugas selesai.',
        ],
    ],

    // Signature roles on the document — typed name MVP (PRD §35).
    'signatories' => ['Yang Menyerahkan', 'Yang Menerima', 'Mengetahui', 'IT'],

    /*
    | The legal entities printed in the letterhead, exactly as the official
    | "Template Form Fixed Transfer Asset IT v1 (2026)" lists them. These are
    | fixed on the paper form - the same four names on every sheet - so they are
    | configuration, not master data lookup. The per-document "Entitas" field is a
    | different thing: which of these the sender or receiver belongs to.
    */
    'entities' => [
        'PT. Mahakarya Sukses Indonesia',
        'PT. Stein Perkasa Internasional',
        'PT. Mitra Elektro Perkasa',
        'PT. Perkasa Injeksi Indonesia',
    ],

    /*
    | Physical completeness items, per document type, as "Ada / Tidak Ada".
    |
    | Structured rather than a free-text accessories box, because the paper form
    | asks each item separately and a signed handover is the record of exactly
    | what came in the box. A single textarea cannot say "charger yes, mouse no"
    | in a way anybody can check later.
    */
    'kelengkapan' => [
        'FAT' => ['Laptop/PC', 'Charger', 'Mouse', 'Tas', 'Accessories'],
        'BAST' => ['Laptop', 'Charger', 'Mouse', 'Tas Laptop', 'Keyboard External', 'Accessories Lain'],
        'TRANSFER' => ['Laptop', 'Charger', 'Mouse', 'Tas', 'Accessories'],
        'RETURN' => ['Laptop', 'Charger', 'Mouse', 'Tas', 'Accessories'],
        'ASSIGNMENT' => ['Laptop', 'Charger', 'Mouse', 'Tas', 'Accessories'],
    ],

    // Wording carried on the face of the document, per type. The generic
    // template draws its heading from here so a RETURN does not arrive headed
    // "Form Transfer Fixed Asset IT".
    'labels' => [
        'FAT' => 'Form Transfer Fixed Asset IT',
        'BAST' => 'Berita Acara Serah Terima Aset IT',
        'TRANSFER' => 'Berita Acara Transfer Aset IT',
        'RETURN' => 'Berita Acara Pengembalian Aset IT',
        'ASSIGNMENT' => 'Form Penyerahan Aset IT',
    ],

    // Generated PDFs live here (local MVP; SharePoint later, PRD §37).
    'storage_path' => 'documents',
];
