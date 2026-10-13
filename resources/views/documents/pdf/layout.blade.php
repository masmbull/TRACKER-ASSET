<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 2cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    .header { border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 16px; }
    .header h1 { font-size: 15px; margin: 0 0 6px; color: #1e40af; }
    .meta { font-size: 10px; color: #334155; margin-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
    .label { width: 38%; color: #475569; }
    /* Inline field name inside a value cell, e.g. "Entity: PT Mito". */
    .field { color: #475569; }
    .field-value { display: block; }
    .lead { margin-bottom: 12px; }
    .signatures { width: 100%; margin-top: 26px; border: none; }
    .signatures td { border: none; text-align: center; padding: 8px; }
    .sig-role { font-size: 9px; color: #475569; text-transform: uppercase; }
    .sig-line { border-bottom: 1px solid #111827; height: 52px; margin: 6px 0; }
    .sig-name { font-weight: bold; font-size: 10px; }
    .footer { margin-top: 22px; font-size: 8px; color: #64748b; text-align: center; }
</style>
</head>
<body>
@php($logoUri = 'data:image/png;base64,' . base64_encode(file_get_contents(public_path('images/mito-logo-white.png'))))
<div class="header">
    <table style="border: none; width: 100%; margin-bottom: 4px;">
        <tr>
            <td style="border: none; width: 150px; vertical-align: top; padding: 6px 10px; background: #ed1c24;">
                <img src="{{ $logoUri }}" style="width: 130px; display: block;" alt="MITO Electronic">
            </td>
            <td style="border: none; vertical-align: top; padding: 0;">
                <h1>@yield('title')</h1>
            </td>
        </tr>
    </table>
    <div class="meta">No. Dokumen: <strong>{{ $document['number'] }}</strong></div>
    <div class="meta">Tanggal: {{ $document['date'] }}</div>
    <div class="meta">Perusahaan: {{ $document['company'] ?? '-' }}</div>
</div>

@yield('content')

@if(!empty($document['notes']))
<table>
    <tr><th>Catatan</th></tr>
    <tr><td>{{ $document['notes'] }}</td></tr>
</table>
@endif

<table class="signatures">
    <tr>
        @foreach([['menyerahkan', 'Yang Menyerahkan'], ['menerima', 'Yang Menerima'], ['mengetahui', 'Mengetahui'], ['it', 'IT']] as $sig)
            <td style="width:25%">
                <div class="sig-role">{{ $sig[1] }}</div>
                <div class="sig-line"></div>
                <div class="sig-name">{{ $document['signatories'][$sig[0]] ?? '—' }}</div>
            </td>
        @endforeach
    </tr>
</table>

<div class="footer">
    Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }} pada {{ now()->format('Y-m-d H:i') }} WIB.
</div>
</body>
</html>
