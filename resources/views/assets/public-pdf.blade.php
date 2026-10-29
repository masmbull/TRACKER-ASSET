<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    {{--
        The public asset document, as PDF.

        Deliberately plain HTML + inline CSS: DomPDF renders a narrow subset, and
        the browser view's rounded cards and grid do not survive into it. What
        matters on paper is the same as on the phone the label was scanned with -
        what this asset is, its specification, and who holds it.
    --}}
    <style>
        @page { margin: 1.4cm 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; line-height: 1.45; }
        .letterhead { border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 12px; }
        .letterhead table { border: none; width: 100%; margin: 0; }
        .letterhead td { border: none; vertical-align: middle; padding: 0; }
        .logo-cell { width: 86px; padding: 6px 8px; background: #ed1c24; text-align: center; }
        .logo-cell img { width: 70px; height: auto; display: block; margin: 0 auto; }
        .entities { padding-left: 12px; }
        .entities .legal { font-size: 9px; font-weight: bold; color: #0f172a; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 13px; margin: 0; color: #1e40af; text-transform: uppercase; }
        .doc-title .sub { font-size: 9px; color: #475569; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #cbd5e1; padding: 3px 7px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
        .section-head { background: #e2e8f0; font-weight: bold; font-size: 9px; }
        .label { width: 34%; color: #475569; }
        .footer { margin-top: 14px; font-size: 7.5px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
@php($logoUri = 'data:image/png;base64,' . base64_encode(file_get_contents(public_path('images/mito-logo-pdf.png'))))
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
                <h1>Kartu Aset IT</h1>
                <div class="sub">No. Aset: {{ $asset->asset_id }}</div>
                @if($issuedAt)<div class="sub">Diterbitkan: {{ $issuedAt->translatedFormat('d F Y') }}</div>@endif
            </td>
        </tr>
    </table>
</div>

<table>
    <tr><th class="section-head" colspan="4">IDENTITAS ASET</th></tr>
    <tr><td class="label">Asset ID</td><td>{{ $asset->asset_id ?? '-' }}</td><td class="label">Kategori</td><td>{{ $asset->category?->name ?? '-' }}</td></tr>
    <tr><td class="label">Merk / Model</td><td>{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: '-' }}</td><td class="label">Serial Number</td><td>{{ $asset->serial_number ?? '-' }}</td></tr>
    <tr><td class="label">Hostname</td><td>{{ $asset->hostname ?? '-' }}</td><td class="label">Status</td><td>{{ $asset->status?->name ?? '-' }}</td></tr>
    <tr><td class="label">Barcode</td><td>{{ $asset->barcode ?? '-' }}</td><td class="label">Sistem Operasi</td><td>{{ $asset->os_version ?? '-' }}</td></tr>
</table>

<table>
    <tr><th class="section-head" colspan="4">KEPEMILIKAN &amp; PENEMPATAN</th></tr>
    <tr><td class="label">Perusahaan</td><td>{{ $asset->company?->name ?? '-' }}</td><td class="label">Lokasi</td><td>{{ $asset->location?->name ?? '-' }}</td></tr>
    <tr><td class="label">Pengguna Saat Ini</td><td>{{ $asset->currentUser?->name ?? 'Tidak ada' }}</td><td class="label">Divisi Pengguna</td><td>{{ $asset->currentUser?->department ?? '-' }}</td></tr>
    <tr><td class="label">PIC IT</td><td>{{ $asset->adminItPic?->name ?? '-' }}</td><td class="label">MAC Address</td><td>{{ $asset->mac_address ?? '-' }}</td></tr>
</table>

<table>
    <tr><th class="section-head" colspan="4">SPESIFIKASI</th></tr>
    <tr><td class="label">Processor</td><td>{{ $asset->processor ?? '-' }}</td><td class="label">RAM</td><td>{{ $asset->ram ?? '-' }}</td></tr>
    <tr><td class="label">Storage</td><td>{{ $asset->storage ?? '-' }}</td><td class="label">IP Statis</td><td>{{ $asset->static_ip ?? '-' }}</td></tr>
</table>

<table>
    <tr><th class="section-head" colspan="4">PEROLEHAN &amp; GARANSI</th></tr>
    <tr><td class="label">Vendor</td><td>{{ $asset->vendor ?? '-' }}</td><td class="label">Tahun Pembelian</td><td>{{ $asset->purchase_year ?? '-' }}</td></tr>
    <tr><td class="label">Tanggal Deploy</td><td>{{ $asset->deploy_date?->translatedFormat('d F Y') ?? '-' }}</td><td class="label">Garansi s/d</td><td>{{ $asset->warranty_until?->translatedFormat('d F Y') ?? '-' }}</td></tr>
    <tr><td class="label">Lisensi Software</td><td colspan="3">{{ $asset->software_license ?? '-' }}</td></tr>
</table>

<div class="footer">
    Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }} dan dapat diverifikasi melalui label QR aset.
</div>
</body>
</html>