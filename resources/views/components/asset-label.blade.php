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
     */
    $width = (float) ($width ?: config('assets.label.width_mm', 90));
    $height = (float) ($height ?: config('assets.label.height_mm', 30));
    $qrSize = (float) ($qrSize ?: config('assets.label.qr_mm', 24));

    $model = trim(($asset->brand ?? '').' '.($asset->model ?? ''));

    // Company and category stand in for "company · business unit": the register
    // has no business-unit column, and the category is the closest existing
    // field (Laptop, Monitor, Printer...). Location is deliberately left off to
    // keep the sticker to four lines.
    $organisation = $asset->company?->name ?? '';

    if ($asset->category?->name) {
        $organisation = $organisation === ''
            ? $asset->category->name
            : $organisation.' · '.$asset->category->name;
    }

    $serial = $asset->serial_number ? 'SN: '.$asset->serial_number : 'SN: —';
    $pixel = (int) round($qrSize * 96 / 25.4);
@endphp

<div class="asset-label"
     style="width: {{ $width }}mm; height: {{ $height }}mm; box-sizing: border-box; border: 0.3mm solid #d1d5db; border-radius: 2mm; background: #ffffff; overflow: hidden; break-inside: avoid; page-break-inside: avoid;">
    <div style="display: flex; align-items: center; width: 100%; height: 100%; box-sizing: border-box; padding: 2.5mm 3mm;">
        <img src="{{ $qrSrc }}" alt="QR code for {{ $asset->asset_id }}" width="{{ $pixel }}" height="{{ $pixel }}"
             style="display: block; flex: 0 0 auto; width: {{ $qrSize }}mm; height: {{ $qrSize }}mm; margin-right: 3mm;">
        <div style="flex: 1 1 auto; min-width: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: center; line-height: 1.15; color: #111827; font-family: 'DejaVu Sans', 'Segoe UI', Arial, sans-serif;">
            <div style="font-size: 4.6mm; font-weight: 700; letter-spacing: -0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $asset->asset_id }}</div>
            <div style="margin-top: 0.4mm; font-size: 3.1mm; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $model !== '' ? $model : '—' }}</div>
            <div style="margin-top: 0.4mm; font-size: 2.6mm; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $serial }}</div>
            <div style="margin-top: 0.4mm; font-size: 2.4mm; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $organisation !== '' ? $organisation : '—' }}</div>
        </div>
    </div>
</div>