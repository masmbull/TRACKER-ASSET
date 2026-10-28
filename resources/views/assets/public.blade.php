<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asset->asset_id }} · Aset IT</title>
    <meta name="theme-color" content="#dc2626">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #111827; }
        .sheet { max-width: 720px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(16,24,40,.1); overflow: hidden; }
        .letterhead { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 2px solid #1e40af; }
        .logo { width: 52px; height: 52px; border-radius: 10px; background: #ed1c24; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .logo img { width: 40px; height: 40px; }
        .entities { font-size: 11px; font-weight: 700; color: #0f172a; line-height: 1.4; }
        .title { padding: 18px 20px 6px; }
        .title h1 { margin: 0; font-size: 18px; letter-spacing: .02em; text-transform: uppercase; color: #1e40af; }
        .title .sub { margin-top: 4px; font-size: 12px; color: #64748b; }
        .badge { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; border-radius: 999px; padding: 3px 12px; font-size: 12px; font-weight: 600; background: #dcfce7; color: #166534; }
        .badge .dot { width: 7px; height: 7px; border-radius: 999px; background: currentColor; }
        .section { padding: 4px 20px 16px; }
        .section h2 { margin: 14px 0 8px; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; }
        dl { margin: 0; display: grid; grid-template-columns: 1fr; gap: 0; }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid #f1f5f9; }
        .row dt { color: #64748b; font-size: 13px; }
        .row dd { margin: 0; font-weight: 600; font-size: 13px; text-align: right; word-break: break-word; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; padding: 0 20px 20px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; border-radius: 10px; padding: 11px 18px; font-size: 14px; font-weight: 600; text-decoration: none; border: 1px solid transparent; }
        .btn-primary { background: #dc2626; color: #fff; }
        .btn-secondary { background: #fff; color: #334155; border-color: #e2e8f0; }
        .foot { text-align: center; font-size: 11px; color: #94a3b8; padding: 14px 16px 28px; }
        @media (min-width: 640px) { dl { grid-template-columns: 1fr 1fr; column-gap: 32px; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="card">
        <div class="letterhead">
            <div class="logo"><img src="{{ asset('images/mito-icon.png') }}" alt="MITO"></div>
            <div class="entities">
                @foreach((array) config('documents.entities', []) as $entity)
                    <div>{{ $entity }}</div>
                @endforeach
            </div>
        </div>

        <div class="title">
            <h1>{{ $asset->asset_id }}</h1>
            <div class="sub">{{ $asset->brand }} {{ $asset->model }}</div>
            <div class="badge"><span class="dot"></span>{{ $asset->status?->name ?? '—' }}</div>
        </div>

        <div class="section">
            <h2>Identitas Aset</h2>
            <dl>
                <div class="row"><dt>Kategori</dt><dd>{{ $asset->category?->name ?? '—' }}</dd></div>
                <div class="row"><dt>Merk / Model</dt><dd>{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: '—' }}</dd></div>
                <div class="row"><dt>Serial Number</dt><dd>{{ $asset->serial_number ?? '—' }}</dd></div>
                <div class="row"><dt>Hostname</dt><dd>{{ $asset->hostname ?? '—' }}</dd></div>
                <div class="row"><dt>Kode Barcode</dt><dd>{{ $asset->barcode ?? '—' }}</dd></div>
                <div class="row"><dt>Status</dt><dd>{{ $asset->status?->name ?? '—' }}</dd></div>
            </dl>

            <h2>Kepemilikan &amp; Penempatan</h2>
            <dl>
                <div class="row"><dt>Perusahaan</dt><dd>{{ $asset->company?->name ?? '—' }}</dd></div>
                <div class="row"><dt>Lokasi</dt><dd>{{ $asset->location?->name ?? '—' }}</dd></div>
                <div class="row"><dt>Pengguna Saat Ini</dt><dd>{{ $asset->currentUser?->name ?? 'Tidak ada' }}</dd></div>
                @if($asset->currentUser?->department)
                    <div class="row"><dt>Divisi Pengguna</dt><dd>{{ $asset->currentUser->department }}</dd></div>
                @endif
                <div class="row"><dt>PIC IT</dt><dd>{{ $asset->adminItPic?->name ?? '—' }}</dd></div>
            </dl>

            <h2>Spesifikasi</h2>
            <dl>
                <div class="row"><dt>Processor</dt><dd>{{ $asset->processor ?? '—' }}</dd></div>
                <div class="row"><dt>RAM</dt><dd>{{ $asset->ram ?? '—' }}</dd></div>
                <div class="row"><dt>Storage</dt><dd>{{ $asset->storage ?? '—' }}</dd></div>
                <div class="row"><dt>Sistem Operasi</dt><dd>{{ $asset->os_version ?? '—' }}</dd></div>
                <div class="row"><dt>MAC Address</dt><dd>{{ $asset->mac_address ?? '—' }}</dd></div>
                <div class="row"><dt>IP Statis</dt><dd>{{ $asset->static_ip ?? '—' }}</dd></div>
            </dl>

            <h2>Perolehan &amp; Garansi</h2>
            <dl>
                <div class="row"><dt>Vendor</dt><dd>{{ $asset->vendor ?? '—' }}</dd></div>
                <div class="row"><dt>Tahun Pembelian</dt><dd>{{ $asset->purchase_year ?? '—' }}</dd></div>
                <div class="row"><dt>Tanggal Deploy</dt><dd>{{ $asset->deploy_date?->translatedFormat('d F Y') ?? '—' }}</dd></div>
                <div class="row"><dt>Garansi s/d</dt><dd>{{ $asset->warranty_until?->translatedFormat('d F Y') ?? '—' }}</dd></div>
                <div class="row"><dt>Lisensi Software</dt><dd>{{ $asset->software_license ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="actions">
            <a class="btn btn-primary" href="{{ route('assets.public.pdf', $asset->qr_token) }}">
                <x-icon name="download" class="h-[18px] w-[18px]" />
                Download PDF
            </a>
        </div>
    </div>

    <div class="foot">
        Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }}.
        @if($issuedAt) Label diterbitkan {{ $issuedAt->translatedFormat('d M Y') }}.@endif
    </div>
</div>
</body>
</html>
