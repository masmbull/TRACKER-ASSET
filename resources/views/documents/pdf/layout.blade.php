<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
{{--
    Shared A4 shell for every generated document.

    The office's paper form is a single sheet: a compact letterhead, a titled
    cover band, then the body sections and one signature block. This shell owns
    only that chrome - the body is rendered by the type template. Keeping the
    density deliberate (7.5-9 px, tight rows, no spacer rows) is what lets the
    whole form fit on one A4 page instead of spilling a signature onto page two.
--}}
<style>
    @page { size: A4 portrait; margin: 10mm 11mm; }
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 8.2px;
        color: #1f2937;
        line-height: 1.22;
        margin: 0;
    }

    /* ---- Letterhead ----
       One compact band: red hairline, logo tile, a clear hierarchy for the four
       legal entities, and a right-aligned title with its own meta card. Kept
       deliberately shallow so the whole form still fits on one A4 sheet. */
    .letterhead {
        width: 100%; border-collapse: collapse;
        border-top: 3px solid #b91c1c;
        border-bottom: 1px solid #cbd5e1;
        padding: 4px 0 5px;
        margin-bottom: 6px;
    }
    .letterhead td { border: 0; padding: 0; vertical-align: middle; }
    .logo-cell { width: 54px; padding: 4px; background: #b91c1c; text-align: center; }
    .logo-cell img { width: 42px; height: auto; display: block; margin: 0 auto; }
    .entities { padding-left: 9px; }
    .entities .entity-primary {
        font-size: 9.5px; font-weight: 800; color: #111827;
        text-transform: uppercase; letter-spacing: .02em; line-height: 1.2;
    }
    .entities .entity-primary::before { content: "■ "; color: #b91c1c; font-size: 8px; }
    .entities .legal { font-size: 7.4px; font-weight: 600; line-height: 1.28; color: #475569; }
    .entities .legal::before { content: "▸ "; color: #b91c1c; font-weight: 800; }
    .entities .tagline {
        margin-top: 2px; font-size: 6.6px; color: #94a3b8;
        text-transform: uppercase; letter-spacing: .06em;
    }
    .entities .tagline::before { content: "› "; color: #b91c1c; }
    .doc-title { text-align: right; width: 40%; }
    .doc-title .badge {
        display: inline-block; margin-bottom: 2px; padding: 0 5px;
        border: 1px solid #fecaca; background: #fef2f2;
        font-size: 6.4px; font-weight: 800; color: #b91c1c;
        text-transform: uppercase; letter-spacing: .08em;
    }
    .doc-title .badge::before { content: "◆ "; font-size: 6px; }
    .doc-title h1 {
        margin: 0 0 3px; padding-bottom: 2px; border-bottom: 2px solid #b91c1c;
        font-size: 10.5px; font-weight: 800; color: #111827;
        text-transform: uppercase; letter-spacing: .03em;
    }
    .doc-title h1::before { content: "▣ "; color: #b91c1c; }
    .doc-meta { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; background: #f8fafc; }
    .doc-meta td { border: 0; padding: 0.5px 5px; font-size: 7.2px; line-height: 1.25; }
    .doc-meta td.meta-label { width: 26%; color: #94a3b8; text-align: left; font-weight: 600; }
    .doc-meta td.meta-value { color: #1e293b; text-align: right; font-weight: 700; }

    .intro {
        font-size: 7.6px; color: #475569; text-align: justify;
        border-left: 2px solid #e2e8f0; padding: 2px 0 2px 7px; margin-bottom: 6px;
    }

    /* ---- Tables ---- */
    table.form { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.form th, table.form td {
        border: 1px solid #cbd5e1; padding: 1.5px 5px;
        text-align: left; vertical-align: top;
    }
    table.form th.section-head {
        background: #eef2f7; color: #1e293b;
        font-size: 8px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase;
        padding: 2.5px 5px; border-color: #94a3b8;
    }
    table.form td.label { width: 33%; color: #475569; background: #f8fafc; font-weight: 600; }
    .pass { color: #047857; font-weight: bold; }
    .fail { color: #b91c1c; font-weight: bold; }
    .statement { text-align: justify; line-height: 1.3; }
    .detail { color: #b91c1c; font-size: 6.8px; font-style: italic; }
    .check-cell { width: 13%; text-align: center; font-size: 7.4px; }

    /* ---- Signatures ---- */
    .sign-grid { width: 100%; border-collapse: collapse; margin-top: 3px; }
    .sign-grid th {
        border: 1px solid #94a3b8; background: #eef2f7;
        font-weight: 800; text-align: center; font-size: 8px;
        text-transform: uppercase; letter-spacing: .04em; color: #1e293b; padding: 2.5px;
    }
    .sign-grid td { border: 1px solid #94a3b8; width: 50%; height: 58px; vertical-align: top; padding: 4px 6px; }
    .sign-cell-role { font-size: 7.6px; font-weight: 700; color: #334155; }
    .sign-cell-dashed { border-bottom: 1px dashed #94a3b8; margin: 4px 0 2px; height: 1px; }
    .sign-cell-name { font-size: 8.4px; font-weight: 700; text-align: center; color: #111827; }
    .sign-cell-date { font-size: 7px; color: #64748b; text-align: center; }
    .sign-cell-image { display: block; height: 24px; max-width: 100%; margin: 1px auto; }

    /* Footer stays on the single page. */
    .footer {
        margin-top: 4px; padding-top: 2px; border-top: 1px solid #e2e8f0;
        font-size: 6.8px; color: #94a3b8; text-align: center;
    }
    .section-head, table.form, .sign-grid, .sign-grid tr { page-break-inside: avoid; }
</style>
</head>
<body>
@php
    $logoPath = $document['company_logo'] ?? null;
    $logoFile = $logoPath && is_file(public_path($logoPath))
        ? public_path($logoPath)
        : public_path('images/mito-logo-pdf.png');
    $logoUri = 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile));
@endphp

<table class="letterhead">
    <tr>
        <td class="logo-cell"><img src="{{ $logoUri }}" alt="MITO Electronic"></td>
        <td class="entities">
            @foreach((array) config('documents.entities', []) as $entity)
                <div class="{{ $loop->first ? 'entity-primary' : 'legal' }}">{{ $entity }}</div>
            @endforeach
            <div class="tagline">IT Asset Management · Formulir Terkendali</div>
        </td>
        <td class="doc-title">
            <span class="badge">Formulir Resmi</span>
            <h1>@yield('title')</h1>
            <table class="doc-meta">
                <tr>
                    <td class="meta-label">No.</td>
                    <td class="meta-value">{{ $document['number'] }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Tanggal</td>
                    <td class="meta-value">{{ $document['date_long'] ?? $document['date'] }}</td>
                </tr>
                @if(filled($document['nomor_surat'] ?? null) && $document['nomor_surat'] !== $document['number'])
                    <tr>
                        <td class="meta-label">No. Surat</td>
                        <td class="meta-value">{{ $document['nomor_surat'] }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="intro">
    Form ini dibuat secara resmi untuk mencatat, mengontrol, dan memonitor proses peminjaman aset Teknologi Informasi milik perusahaan, yang dipinjam oleh karyawan untuk keperluan operasional, proyek, atau kegiatan resmi perusahaan.
</div>

@yield('content')

<div class="footer">
    Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }} pada {{ now()->translatedFormat('d M Y H:i') }} WIB.
</div>
</body>
</html>
