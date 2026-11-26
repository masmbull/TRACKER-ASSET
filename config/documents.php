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
        'FAT' => 'FAT/IT-{month}-{year}/{seq:4}',
        'BAST' => 'BAST/IT-{month}-{year}/{seq:4}',
        'TRANSFER' => 'AST/TRF-{month}-{year}/{seq:4}',
        'RETURN' => 'AST/RET-{month}-{year}/{seq:4}',
        'ASSIGNMENT' => 'AST/ASG-{month}-{year}/{seq:4}',
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
            'statement' => 'Aset ini diserahkan oleh pihak penyerah kepada penerima untuk digunakan dalam mendukung pelaksanaan tugas. Penerima bertanggung jawab menjaga keutuhan, keamanan, dan kondisi aset selama penggunaan, serta wajib mengembalikannya kepada pihak penyerah dalam kondisi baik sesuai keadaan saat diterima, kecuali kerusakan wajar akibat pemakaian normal.',
        ],
        'BAST' => [
            'handover_date' => 'Tanggal Serah Terima',
            'received_date' => 'Tanggal Diterima',
            'statement' => 'Dengan ini pihak penyerah menyerahkan aset kepada penerima, dan penerima menyatakan telah menerima aset tersebut dalam kondisi baik serta lengkap sesuai daftar yang tercantum dalam dokumen ini. Penerima berkewajiban menggunakan aset semata-mata untuk keperluan pekerjaan, menjaga dan memeliharanya dengan baik, serta mengembalikannya dalam kondisi baik apabila tidak lagi digunakan atau sesuai dengan ketentuan yang berlaku.',
        ],
        'RETURN' => [
            'handover_date' => 'Tanggal Pengembalian',
            'received_date' => 'Tanggal Diterima Kembali',
            'statement' => 'Aset ini dikembalikan oleh penerima kepada pihak penyerah dalam kondisi baik sesuai keadaan saat diterima, beserta seluruh aksesoris dan kelengkapannya, kecuali kerusakan wajar akibat penggunaan normal.',
        ],
        'ASSIGNMENT' => [
            'handover_date' => 'Tanggal Penyerahan',
            'received_date' => 'Tanggal Diterima',
            'statement' => 'Aset ini diserahkan kepada penerima untuk dipergunakan dalam pelaksanaan tugas dan tanggung jawabnya. Penerima wajib menjaga, memelihara, dan menggunakan aset secara bertanggung jawab, serta mengembalikannya kepada pihak penyerah setelah tugas selesai atau sesuai dengan ketentuan yang berlaku.',
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
