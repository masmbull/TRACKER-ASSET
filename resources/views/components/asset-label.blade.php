@props([
    'asset',
    'qrSrc',
    'width' => null,
    'height' => null,
    'qrSize' => null,
])

@php
    /*
     * One label, one source of truth.
     *
     * The browser preview, the print page and the DomPDF document all render
     * this component, so the sticker that comes out of the printer cannot drift
     * from the one on screen. Sizes are inline millimetres rather than classes:
     * DomPDF does not load the Tailwind build, and a class that happens to work
     * in the browser would silently collapse to an unstyled block in the PDF.
     *
     * The card is a row: code on the left, four lines of text on the right.
     * Stacked vertically the sticker wasted the width it was given and pushed
     * the sheet to a second page for no reason.
     */
    $width = (float) ($width ?: config('assets.label.width_mm', 90));
    $height = (float) ($height ?: config('assets.label.height_mm', 30));
    $qrSize = (float) ($qrSize ?: config('assets.label.qr_mm', 21));

    $model = trim(($asset->brand ?? '').' '.($asset->model ?? ''));

    $serial = ($asset->serial_number ?? '') !== ''
        ? 'SN: '.$asset->serial_number
        : 'SN: —';

    // The footer is the organisation mark and nothing else. The company is not
    // printed (every label belongs to the same organisation) and neither is the
    // location: two identical laptops on two floors are told apart by the asset
    // code above, and a site name on the sticker goes stale the first time a
    // machine is moved.
    $footer = 'MITO GROUP ASSETS';

    $pixel = (int) round($qrSize * 96 / 25.4);
@endphp

<div class="asset-label"
     style="width: {{ $width }}mm; height: {{ $height }}mm; box-sizing: border-box; border: 0.3mm solid #d1d5db; border-radius: 2mm; background: #ffffff; overflow: hidden; break-inside: avoid; page-break-inside: avoid;">
    <div style="display: flex; flex-direction: row; align-items: center; gap: 4mm; width: 100%; height: 100%; box-sizing: border-box; padding: 2.5mm 3mm;">
        <img src="{{ $qrSrc }}" alt="QR code for {{ $asset->asset_id }}" width="{{ $pixel }}" height="{{ $pixel }}"
             style="display: block; flex: 0 0 auto; width: {{ $qrSize }}mm; height: {{ $qrSize }}mm;">
        <div style="flex: 1 1 auto; min-width: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: center; text-align: left; line-height: 1.35; font-family: 'DejaVu Sans', 'Segoe UI', Arial, sans-serif;">
            <div style="font-size: 3.2mm; font-weight: 700; color: #121213; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $asset->asset_id }}</div>
            <div style="font-size: 2.6mm; font-weight: 500; color: #4e4e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $model !== '' ? $model : '—' }}</div>
            <div style="font-size: 2.4mm; font-weight: 500; color: #834100; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $serial }}</div>
            <div style="font-size: 2.2mm; color: #4e4e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $footer }}</div>
        </div>
    </div>
</div>