<?php

/**
 * Configuration for the legacy Excel import (PRD §8, §25, §26).
 *
 * Header keys are normalized: lowercased, and every character that is not
 * a-z0-9 removed. Example: "Serial Number" -> "serialnumber",
 * "Harga (Rp)" -> "hargarp", "Tgl Serah (U1→U2)" -> "tglserahu1u2".
 */
return [

    // Sheet that holds the asset rows. Other sheets (e.g. a dashboard sheet) are ignored.
    'sheet_names' => ['Overall', 'Details'],

    // Maximum number of data rows accepted in a single import.
    'max_rows' => 20000,

    // Rows shown in the preview table before importing.
    'preview_limit' => 50,

    // Legacy columns that hold a person name only. Matching users are looked up by
    // name/email; when missing a placeholder directory user is created so that the
    // assignment history is preserved (PRD §16). Placeholders can never log in.
    'create_missing_users' => true,
    'placeholder_email_domain' => 'imported.local',

    // Used when the sheet has no company column and no company can be resolved.
    'default_company' => 'PT. Mahakarya Sukses Indonesia',

    // Files are staged here (relative to storage/app) between analyse and confirm.
    'staging_directory' => 'imports',

    /*
    |--------------------------------------------------------------------------
    | Legacy column -> database destination
    |--------------------------------------------------------------------------
    */
    'column_map' => [
        'companyname' => 'company_name',
        'assetid' => 'asset_id',
        'kategori' => 'category_name',
        'merk' => 'brand',
        'model' => 'model',
        // IT Assets.xlsx (sheet "Overall") writes brand and model in one cell.
        'merkmodel' => 'brand',
        'serialnumber' => 'serial_number',
        'hostname' => 'hostname',
        'processor' => 'processor',
        'spec' => 'processor',
        'storage' => 'storage',
        'ram' => 'ram',
        'osversi' => 'os_version',
        'os' => 'os_version',
        'lokasi' => 'location_name',
        'tahunpembelian' => 'purchase_year',
        'vendor' => 'vendor',
        'hargarp' => 'purchase_price',
        'masapakaithn' => 'useful_life_years',
        'masapakaitahun' => 'useful_life_years',
        'statusaset' => 'status_name',
        'statusasset' => 'status_name',
        'garansisd' => 'warranty_until',
        'tanggaldeploy' => 'deploy_date',
        'lisensiswoffice' => 'software_license',
        'macaddress' => 'mac_address',
        'ipaddressstatis' => 'static_ip',
        'adminitpic' => 'admin_it_pic',
        'riwayatperbaikancatatan' => 'repair_notes',
        'catatan' => 'repair_notes',
        'tglretiredisposal' => 'retire_date',
        'qr' => 'qr_code',
        'barcode' => 'barcode',

        // Assignment history columns (PRD §16, §26).
        'userpertama' => 'assignment_1_user',
        'tglmulaiu1' => 'assignment_1_start',
        'tglserahu1u2' => 'assignment_1_end',
        'userkedua' => 'assignment_2_user',
        'tglmulaiu2' => 'assignment_2_start',
        'tglserahu2u3' => 'assignment_2_end',
        'userketiga' => 'assignment_3_user',
        'tglmulaiu3' => 'assignment_3_start',
        'tglserahu3u4' => 'assignment_3_end',
        'usersekarang' => 'current_user',
        'usersaatini' => 'current_user',
        'tglmulaicurrent' => 'current_assignment_start',
        'tglserahterakhir' => 'current_assignment_end',
    ],

    /*
    |--------------------------------------------------------------------------
    | Assignment history extraction order
    |--------------------------------------------------------------------------
    */
    'assignments' => [
        ['user' => 'assignment_1_user', 'start' => 'assignment_1_start', 'end' => 'assignment_1_end'],
        ['user' => 'assignment_2_user', 'start' => 'assignment_2_start', 'end' => 'assignment_2_end'],
        ['user' => 'assignment_3_user', 'start' => 'assignment_3_start', 'end' => 'assignment_3_end'],
        ['user' => 'current_user', 'start' => 'current_assignment_start', 'end' => 'current_assignment_end'],
    ],

];
