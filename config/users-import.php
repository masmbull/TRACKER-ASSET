<?php

/**
 * People directory import (name + office email, optionally an Asset ID).
 *
 * Headers are normalised the same way as the asset import: lowercased, with
 * every non-alphanumeric character removed. "Office Email" -> "officeemail".
 */
return [

    'sheet_names' => ['Users', 'User', 'Sheet1', 'Overall', 'Details'],

    'max_rows' => 20000,

    'preview_limit' => 50,

    'staging_directory' => 'imports',

    // The two required columns are matched case-insensitively, so both the
    // office template ("Full Name", "Office Email") and common variants work.
    'column_map' => [
        'fullname' => 'name',
        'name' => 'name',
        'nama' => 'name',
        'namalengkap' => 'name',
        'officeemail' => 'email',
        'email' => 'email',
        'emailkantor' => 'email',
        'workemail' => 'email',
        'assetid' => 'asset_id',
        'nomorasset' => 'asset_id',
        'kodeaset' => 'asset_id',
    ],

    // Imported people cannot sign in until they are given a role/password or
    // are provisioned through Entra. They are active so asset forms can pick
    // them; set to false to require an explicit activation step.
    'default_role' => 'viewer',
    'default_is_active' => true,
];
