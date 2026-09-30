<?php

/**
 * Asset labelling — QR codes for physical assets (PRD §41).
 *
 * A QR code here is a label somebody sticks on a laptop, so two things matter
 * more than they would for an on-screen code: it has to be scannable in bad
 * light on a scuffed sticker, and it has to carry enough of the MITO mark that
 * nobody can claim they did not know whose equipment it was.
 */
return [

    'qr' => [

        /*
        | Where a scan should land.
        |
        | This is deliberately NOT derived from the incoming request. Built as a
        | relative URL it resolves to whatever host served the page, so a code
        | generated in development encodes http://localhost:8000/assets/12 and is
        | dead to a phone that scans it. Set ASSET_QR_BASE_URL to the address the
        | fleet can actually reach.
        |
        | "?: not env(...)" is deliberate. Laravel's env() only falls back when the
        | key is absent, so a blank "ASSET_QR_BASE_URL=" left in .env would win and
        | quietly turn every label back into a relative URL - the exact failure
        | this line exists to stop, reached by the most natural typo there is.
        */
        'base_url' => env('ASSET_QR_BASE_URL') ?: env('APP_URL', 'http://localhost:8000'),

        /*
        | The mark placed in the middle of the code.
        |
        | Referenced as a URL rather than inlined as base64. A sheet of 200 labels
        | would otherwise carry 200 copies of the file inside it - around 5 MB of
        | repeated image data for a page of print-ready squares.
        */
        'logo' => env('ASSET_QR_LOGO', '/images/mito-icon.png'),

        'logo_ratio' => 0.20,

        /*
        | Why the mark has to be small and the error correction has to be high.
        |
        | A logo is an occluder: every module it covers is a module the scanner
        | cannot read. "H" recovers about 30% of the code, which is what buys the
        | space for the mark; at the default "M" (~15%) a logo this size starts
        | costing real scans on a scratched sticker. Guidance for a centred logo is
        | under a quarter of the code, and the logo sits on a white plate so it
        | never lands on black modules it cannot be told apart from.
        */
        'error_correction' => 'H',

        'size' => 300,
        'margin' => 2,

        // Printed under the code on every label.
        'caption' => 'MITO GROUP ASSETS',

        // Labels per printed sheet. Beyond this the page gets slow to render and
        // awkward to handle; paginate rather than trying to print 400 at once.
        'sheet_size' => 60,
    ],

];
