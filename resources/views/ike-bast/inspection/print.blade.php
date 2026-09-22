<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $session->session_number }} — IKE_BAST Inspection</title>
    <style>
        @page { margin: 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { font-size: 15px; margin: 0 0 4px; color: #1e40af; }
        .meta { font-size: 10px; color: #334155; margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 5px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 9px; text-transform: uppercase; }
        .header { border-bottom: 2px solid #1e40af; padding-bottom: 6px; margin-bottom: 12px; }
        .signatures { margin-top: 24px; }
        .signatures td { border: none; text-align: center; padding: 6px; }
        .sig-line { border-bottom: 1px solid #111827; height: 48px; }
        .sig-role { font-size: 9px; color: #475569; }
        .footer { margin-top: 18px; font-size: 8px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
<div class="header">
    <h1>IKE_BAST INSPECTION TABLE</h1>
    <div class="meta">No. Dokumen: <strong>{{ $session->session_number }}</strong></div>
    <div class="meta">Tanggal Inspeksi: {{ $session->inspection_date?->format('Y-m-d') ?? '-' }}</div>
    <div class="meta">Jenis: {{ $session->kind }} · Status: {{ strtoupper($session->status) }}</div>
    <div class="meta">Diperiksa oleh: {{ $session->creator?->name ?? '-' }}</div>
</div>

<table>
    <tr><th colspan="2">Informasi Aset</th></tr>
    <tr><td style="width:35%">Asset ID</td><td>{{ $session->asset?->asset_id ?? '-' }}</td></tr>
    <tr><td>Kategori</td><td>{{ $session->asset?->category?->name ?? '-' }}</td></tr>
    <tr><td>Merk / Model</td><td>{{ trim(($session->asset?->brand ?? '') . ' ' . ($session->asset?->model ?? '')) ?: '-' }}</td></tr>
    <tr><td>Serial Number</td><td>{{ $session->asset?->serial_number ?? '-' }}</td></tr>
    <tr><td>Hostname</td><td>{{ $session->asset?->hostname ?? '-' }}</td></tr>
    <tr><td>Lokasi</td><td>{{ $session->asset?->location?->name ?? '-' }}</td></tr>
    <tr><td>Pengguna Saat Ini</td><td>{{ $session->asset?->currentUser?->name ?? '-' }}</td></tr>
    <tr><td>Referensi Transfer</td><td>{{ $session->transfer?->transfer_number ?? '-' }}</td></tr>
</table>

<table>
    <tr>
        <th>#</th>
        <th>Item Description</th>
        <th>Item Code</th>
        <th>Barcode</th>
        <th>Serial Number</th>
        <th>Unit</th>
        <th>Qty Planned</th>
        <th>Qty Received</th>
        <th>Condition</th>
        <th>Test</th>
        <th>Defective</th>
        <th>Remarks</th>
    </tr>
    @foreach($session->lines as $line)
        <tr>
            <td>{{ $line->seq }}</td>
            <td>{{ $line->item_desc ?? '-' }}</td>
            <td>{{ $line->item_code ?? '-' }}</td>
            <td>{{ $line->barcode ?? '-' }}</td>
            <td>{{ $line->serial_number ?? '-' }}</td>
            <td>{{ $line->unit ?? '-' }}</td>
            <td>{{ $line->qty_planned ?? '-' }}</td>
            <td>{{ $line->qty_received ?? '-' }}</td>
            <td>{{ $line->physical_condition ?? '-' }}</td>
            <td>{{ strtoupper($line->test_result) }}</td>
            <td>{{ $line->is_defective ? 'YES' : 'NO' }}</td>
            <td>{{ $line->remarks ?? '-' }}</td>
        </tr>
    @endforeach
</table>

@if($session->notes)
    <table>
        <tr><th>Catatan</th></tr>
        <tr><td>{{ $session->notes }}</td></tr>
    </table>
@endif

<table class="signatures">
    <tr>
        <td style="width:33%"><div class="sig-role">Yang Menyerahkan</div><div class="sig-line"></div></td>
        <td style="width:33%"><div class="sig-role">Yang Menerima</div><div class="sig-line"></div></td>
        <td style="width:33%"><div class="sig-role">IT / Mengetahui</div><div class="sig-line"></div></td>
    </tr>
</table>

<div class="footer">
    Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }} pada {{ now()->format('Y-m-d H:i') }} WIB.
</div>
</body>
</html>
