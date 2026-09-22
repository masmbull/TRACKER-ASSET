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

    // Default FAT acceptance checklist; document_templates rows may extend it later.
    'fat_checklist' => [
        'Perangkat menyala dan boot normal',
        'Kondisi fisik baik, tidak ada kerusakan',
        'Port fisik (USB/HDMI/LAN) berfungsi',
        'Baterai & charger berfungsi (jika ada)',
        'Processor/RAM/Storage sesuai data terdaftar',
        'OS terinstal dan berjalan normal',
        'Aksesoris lengkap sesuai catatan serah terima',
    ],

    // Signature roles on the document — typed name MVP (PRD §35).
    'signatories' => ['Yang Menyerahkan', 'Yang Menerima', 'Mengetahui', 'IT'],

    // Generated PDFs live here (local MVP; SharePoint later, PRD §37).
    'storage_path' => 'documents',
];
