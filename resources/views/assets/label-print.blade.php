<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asset labels</title>
    @php
        $width = (float) config('assets.label.width_mm', 90);
        $gap = (float) config('assets.label.gap_mm', 4);
        $sheetUrl = route('assets.qr.sheet');
        $pdfUrl = route('assets.qr.pdf');
        if ($query = request()->getQueryString()) {
            $sheetUrl .= '?'.$query;
            $pdfUrl .= '?'.$query;
        }
    @endphp
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f1f5f9;
            color: #111827;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px 16px 0;
        }
        .toolbar h1 { margin: 0; font-size: 15px; }
        .toolbar p { margin: 2px 0 0; font-size: 12px; color: #64748b; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 10px;
            border: 1px solid transparent;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-primary { background: #dc2626; color: #fff; }
        .btn-secondary { background: #fff; color: #334155; border-color: #e2e8f0; }
        .stage { max-width: 1000px; margin: 0 auto; padding: 28px 16px 56px; }
        .label-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, {{ $width }}mm);
            gap: {{ $gap }}mm;
            justify-content: start;
        }
        .label-grid > div { break-inside: avoid; page-break-inside: avoid; }
        .empty { padding: 48px 0; text-align: center; color: #64748b; font-size: 14px; }
        @media print {
            @page {
                size: {{ config('assets.label.page_size', 'A4') }};
                margin: {{ config('assets.label.page_margin_mm', 10) }}mm;
            }
            body { background: #fff; }
            .toolbar { display: none !important; }
            .stage { max-width: none; padding: 0; }
            .label-grid { gap: {{ $gap }}mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>{{ $assets->count() }} {{ Str::plural('label', $assets->count()) }}</h1>
            <p>Print at 100% scale so each {{ $width }} × {{ config('assets.label.height_mm', 30) }} mm label keeps its physical size.</p>
        </div>
        <div class="actions">
            <a href="{{ $sheetUrl }}" class="btn btn-secondary">Back to labels</a>
            @if($assets->isNotEmpty())
                <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
                <a href="{{ $pdfUrl }}" class="btn btn-secondary">Download PDF</a>
            @endif
        </div>
    </div>

    <div class="stage">
        @if($assets->isEmpty())
            <p class="empty">No assets selected. Go back and tick at least one label.</p>
        @else
            <div class="label-grid">
                @foreach($assets as $asset)
                    <div>
                        <x-asset-label :asset="$asset" :qr-src="route('assets.qr', [$asset, 'plain' => 1])" />
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if(request()->boolean('auto') && $assets->isNotEmpty())
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>