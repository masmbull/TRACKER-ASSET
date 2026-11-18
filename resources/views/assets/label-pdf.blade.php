<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: {{ config('assets.label.page_margin_mm', 10) }}mm; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        tr { page-break-inside: avoid; }
        td { vertical-align: top; padding: 0 2mm 4mm 0; }
    </style>
</head>
<body>
    @if(collect($labels)->isEmpty())
        <p style="font-size: 10px; color: #64748b;">No assets selected.</p>
    @else
        <table>
            @foreach(collect($labels)->chunk($columns) as $row)
                <tr>
                    @foreach($row as $label)
                        <td style="width: {{ round(100 / $columns, 4) }}%;">
                            <x-asset-label :asset="$label['asset']" :qr-src="$label['qrSrc']" />
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>