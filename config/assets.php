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
        | Inlined into each label as a data URI rather than referenced by URL. An
        | SVG shown through an <img> tag is rendered in secure static mode, where
        | a browser will not fetch an external reference - so a URL here means
        | the logo silently disappears from the label sheet, which is the one
        | place it matters. The cost is bounded by sheet_size below.
        |
        | Site-relative, resolved under public/ by the service.
        */
        'logo' => env('ASSET_QR_LOGO', '/images/mito-icon.png'),

        'logo_ratio' => 0.20,

        /*
        | Why the mark has to be small and the error correction has to be high.
        |
        | A logo is an occluder: every module it covers is a module the scanner
        | cannot read. "H" recovers about 30% of the code, which is what buys the
        | space for the mark; guidance for a centred logo is under a quarter of the
        | code. The logo sits on a white plate so it never lands on black modules
        | it cannot be told apart from.
        |
        | Measured, not assumed. tools/verify-qr-scannability.py rasterises a real
        | label and decodes it with OpenCV, degrading it the way a phone camera
        | would. At a 37mm label: H reads 30/30, Q 30/30, M 21/30. M is the
        | interesting result - not merely weaker but erratic, passing and failing
        | as the label shrinks, which produces stickers that scan on a desk and
        | fail in a warehouse. Re-run that script after changing either number.
        |
        | Verified at print sizes down to 24mm. Below roughly 30mm every level
        | starts to struggle, so a small label wants a bigger plate ratio turned
        | down, not a louder mark.
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

    /*
    | Compact asset identification label (PRD §41).
    |
    | A sticker, not a poster: 90 x 30 mm is the common IT-asset label size, and
    | the QR takes roughly a quarter of the width. Every value is millimetres so
    | the browser, the print stylesheet and DomPDF all agree on the physical
    | size; changing the paper stock later is a config change, not a rewrite.
    |
    | page_size is what the PDF and print stylesheet ask for. Keep the label
    | width below the printable width or the grid will fall back to one column.
    */
    'label' => [
        'width_mm' => (float) env('ASSET_LABEL_WIDTH_MM', 90),
        'height_mm' => (float) env('ASSET_LABEL_HEIGHT_MM', 30),
        'qr_mm' => (float) env('ASSET_LABEL_QR_MM', 21),
        'gap_mm' => (float) env('ASSET_LABEL_GAP_MM', 4),
        'page_margin_mm' => (float) env('ASSET_LABEL_PAGE_MARGIN_MM', 10),
        'page_size' => env('ASSET_LABEL_PAGE_SIZE', 'A4'),
    ],

];
