<?php

/**
 * Report which PHP extensions this image actually has.
 *
 * Runs in the image before `composer install`, so a missing extension is named
 * here rather than two hundred lines later as a platform-requirement error that
 * does not say which package wanted it.
 *
 * A warning, never a failure. The contents of a tagged base image can change
 * between builds, so asserting that a particular extension is present turns
 * that drift into a red build whose cause is invisible. Say what is missing and
 * let composer be the judge - it knows what it actually requires.
 *
 * The list is what phpspreadsheet and dompdf declare, plus what this app uses
 * directly. Anything absent here is worth knowing about before the build is
 * three minutes in.
 */
$required = [
    // Required by phpspreadsheet - the Excel import.
    'dom',
    'simplexml',
    'xml',
    'xmlreader',
    'xmlwriter',
    'libxml',
    // Required by dompdf - the generated PDFs.
    'mbstring',
    // Required by the app and by Laravel itself.
    'fileinfo',
    'json',
    'ctype',
    'openssl',
    'tokenizer',
    'filter',
    'session',
    // Installed by the Dockerfile steps below this one.
    'gd',
    'intl',
    'zip',
    'pdo_pgsql',
    'bcmath',
    // phpredis (PECL). The only Redis client in the image: config pins
    // client => phpredis and composer carries no predis. The deployed stack runs
    // sessions, cache and queue on Redis, so this missing is a production
    // failure, not a warning about an optional feature.
    'redis',
];

$loaded = get_loaded_extensions();

echo '--- PHP '.PHP_VERSION.' extensions ('.count($loaded).' loaded) ---'.PHP_EOL;

$missing = array_values(array_diff($required, $loaded));

if ($missing === []) {
    echo 'All extensions required by phpspreadsheet, dompdf and this app are present.'.PHP_EOL;

    return;
}

echo 'WARNING: not present in this image: '.implode(', ', $missing).PHP_EOL;
echo 'If composer install reports a platform requirement failure, start here.'.PHP_EOL;
