<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $session->session_number }} — Inspection</title>
    <style>
        @page { margin: 1.2cm 1.4cm; }
        body { font-family: DejaVu Sans, 'Segoe UI', sans-serif; font-size: 10px; color: #111827; line-height: 1.35; }
        .letterhead { border-bottom: 2px solid #1e40af; padding-bottom: 6px; margin-bottom: 10px; }
        .letterhead table { border: none; width: 100%; margin: 0; }
        .letterhead td { border: none; vertical-align: middle; padding: 0; }
        .logo-cell { width: 128px; padding: 5px 8px; background: #ed1c24; }
        .logo-cell img { width: 110px; display: block; }
        .entities { padding-left: 12px; }
        .entities .legal { font-size: 9px; font-weight: bold; color: #0f172a; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 12.5px; margin: 0; color: #1e40af; text-transform: uppercase; }
        .doc-title .sub { font-size: 8.5px; color: #475569; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #cbd5e1; padding: 3px 6px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
        .section-head { background: #e2e8f0; font-weight: bold; font-size: 9px; }
        .label { width: 34%; color: #475569; }
        .pass { color: #027a48; font-weight: bold; }
        .fail { color: #b42318; font-weight: bold; }
        .sign { border: none; margin-top: 10px; }
        .sign td { border: none; width: 50%; padding: 0 8px 0 0; vertical-align: top; }
        .sign-box { border: 1px solid #cbd5e1; }
        .sign-box th { background: #e2e8f0; }
        .sign-row { height: 42px; }
        .footer { margin-top: 10px; font-size: 7.5px; color: #64748b; text-align: center; }
        .print-bar { max-width: 780px; margin: 0 auto 12px; text-align: right; }
        .print-bar button { background: #465fff; color: #fff; border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; cursor: pointer; }
        @media print { .print-bar { display: none; } }
        .sheet { max-width: 780px; margin: 0 auto; }
    </style>
</head>
<body>
@php($logoUri = 'data:image/png;base64,' . base64_encode(file_get_contents(public_path('images/mito-logo-white.png'))))
<div class="print-bar"><button onclick="window.print()">Print</button></div>

<div class="sheet">
    <div class="letterhead">
        <table>
            <tr>
                <td class="logo-cell"><img src="{{ $logoUri }}" alt="MITO Electronic"></td>
                <td class="entities">
                    @foreach((array) config('documents.entities', []) as $entity)
                        <div class="legal">{{ $entity }}</div>
                    @endforeach
                </td>
                <td class="doc-title">
                    <h1>Lembar Inspeksi Aset IT</h1>
                    <div class="sub">No. {{ $session->session_number }}</div>
                    <div class="sub">Tanggal: {{ $session->inspection_date?->translatedFormat('d F Y') ?? '-' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table>
        <tr><th class="section-head" colspan="2">INFORMASI INSPEKSI</th></tr>
        <tr><td class="label">Jenis</td><td>{{ $session->kind }}</td></tr>
        <tr><td class="label">Status</td><td>{{ strtoupper($session->status) }}</td></tr>
        <tr><td class="label">Asset ID</td><td>{{ $session->asset?->asset_id ?? '-' }}</td></tr>
        <tr><td class="label">Kategori</td><td>{{ $session->asset?->category?->name ?? '-' }}</td></tr>
        <tr><td class="label">Merk / Model</td><td>{{ trim(($session->asset?->brand ?? '') . ' ' . ($session->asset?->model ?? '')) ?: '-' }}</td></tr>
        <tr><td class="label">Serial Number</td><td>{{ $session->asset?->serial_number ?? '-' }}</td></tr>
        <tr><td class="label">Hostname</td><td>{{ $session->asset?->hostname ?? '-' }}</td></tr>
        <tr><td class="label">Lokasi</td><td>{{ $session->asset?->location?->name ?? '-' }}</td></tr>
        <tr><td class="label">Pengguna Saat Ini</td><td>{{ $session->asset?->currentUser?->name ?? '-' }}</td></tr>
        <tr><td class="label">Referensi Transfer</td><td>{{ $session->transfer?->transfer_number ?? '-' }}</td></tr>
        <tr><td class="label">Diperiksa oleh</td><td>{{ $session->creator?->name ?? '-' }}</td></tr>
    </table>

    <table>
        <tr>
            <th class="section-head" colspan="9">ITEM PEMERIKSAAN</th>
        </tr>
        <tr>
            <th>#</th>
            <th>Item</th>
            <th>Item Code</th>
            <th>Serial</th>
            <th>Qty Plan</th>
            <th>Qty Recv</th>
            <th>Kondisi</th>
            <th>Test</th>
            <th>Keterangan</th>
        </tr>
        @forelse($session->lines as $line)
            <tr>
                <td>{{ $line->seq }}</td>
                <td>{{ $line->item_desc ?? '-' }}</td>
                <td>{{ $line->item_code ?? '-' }}</td>
                <td>{{ $line->serial_number ?? '-' }}</td>
                <td>{{ $line->qty_planned ?? '-' }}</td>
                <td>{{ $line->qty_received ?? '-' }}</td>
                <td>{{ $line->physical_condition ?? '-' }}</td>
                <td class="{{ $line->test_result === 'fail' ? 'fail' : ($line->test_result === 'pass' ? 'pass' : '') }}">{{ strtoupper($line->test_result ?? 'na') }}</td>
                <td>{{ $line->remarks ?? '-' }}{{ $line->is_defective ? ' (rusak)' : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="9">Tidak ada item.</td></tr>
        @endforelse
    </table>

    @if($session->notes)
        <table>
            <tr><th class="section-head">CATATAN</th></tr>
            <tr><td>{{ $session->notes }}</td></tr>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td><table class="sign-box"><tr><th>Yang Menyerahkan</th></tr><tr><td class="sign-row"></td></tr></table></td>
            <td><table class="sign-box"><tr><th>Yang Menerima</th></tr><tr><td class="sign-row"></td></tr></table></td>
            <td><table class="sign-box"><tr><th>IT / Mengetahui</th></tr><tr><td class="sign-row"></td></tr></table></td>
        </tr>
    </table>

    <div class="footer">
        Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }} pada {{ now()->translatedFormat('d M Y H:i') }} WIB.
    </div>
</div>
</body>
</html>