<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label {{ $asset->asset_id }}</title>
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
            max-width: 720px;
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
        .stage {
            display: flex;
            justify-content: center;
            padding: 32px 16px 56px;
        }
        .stage .asset-label { box-shadow: 0 12px 32px rgba(15, 23, 42, .12); }
        @media print {
            @page {
                size: {{ config('assets.label.page_size', 'A4') }};
                margin: {{ config('assets.label.page_margin_mm', 10) }}mm;
            }
            body { background: #fff; }
            .toolbar { display: none !important; }
            .stage { padding: 0; }
            .stage .asset-label { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>Asset label · {{ $asset->asset_id }}</h1>
            <p>Print on {{ config('assets.label.page_size', 'A4') }} paper at 100% scale (no “fit to page”), {{ config('assets.label.page_margin_mm', 10) }} mm margins, so the {{ config('assets.label.width_mm', 90) }} × {{ config('assets.label.height_mm', 30) }} mm label keeps its size.</p>
        </div>
        <div class="actions">
            <a href="{{ route('assets.show', $asset) }}" class="btn btn-secondary">Back to asset</a>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print label</button>
            <a href="{{ route('assets.label.pdf', $asset) }}" class="btn btn-secondary">Download PDF</a>
        </div>
    </div>

    <div class="stage">
        <x-asset-label :asset="$asset" :qr-src="route('assets.qr', [$asset, 'plain' => 1])" />
    </div>

    @if(request()->boolean('auto'))
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>