<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
{{--
    Shared shell for every generated document.

    Reproduces the official paper form "Template Form Fixed Transfer Asset IT
    v1 (2026)": the four company names in the letterhead, then the document's own
    sections (rendered by the type template), then the signature block.

    The layout owns the letterhead and nothing else. It used to also paint a
    four-column "Yang Menyerahkan / Yang Menerima / Mengetahui / IT" signature
    table onto every document, on top of the two signature blocks each template
    already drew - so a BAST arrived with six places to sign and no indication
    which four were real. Signatures belong to the form, not the shell.
--}}
<style>
    @page { margin: 1cm 1.3cm; size: auto; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; line-height: 1.28; }
    /* ---- Letterhead ------------------------------------------------------
       One compact band: red hairline on top, logo tile, the legal entities in
       a readable hierarchy, and a right-aligned title with its own meta card.
       Everything here is deliberately shallow - a tall decorative header is
       what pushed the signature block onto a second page. */
    .letterhead {
        border-top: 3px solid #b91c1c;
        border-bottom: 1px solid #cbd5e1;
        padding: 6px 0 7px;
        margin-bottom: 7px;
    }
    .letterhead table { border: none; width: 100%; margin: 0; }
    .letterhead td { border: none; vertical-align: middle; padding: 0; }
    .logo-cell { width: 72px; padding: 5px; background: #b91c1c; text-align: center; }
    .logo-cell img { width: 56px; height: auto; display: block; margin: 0 auto; image-rendering: auto; }
    .entities { padding-left: 11px; }
    .entities .entity-primary {
        font-size: 10.5px; font-weight: 800; color: #111827;
        text-transform: uppercase; letter-spacing: .03em; line-height: 1.2;
    }
    .entities .legal { font-size: 8.3px; font-weight: 600; line-height: 1.3; color: #475569; }
    .entities .legal::before { content: "• "; color: #b91c1c; font-weight: 800; }
    .entities .legal:first-of-type::before { content: ""; }
    .entities .tagline {
        margin-top: 3px; font-size: 7.2px; color: #94a3b8;
        text-transform: uppercase; letter-spacing: .08em;
    }
    .doc-title { text-align: right; width: 40%; }
    .doc-title .badge {
        display: inline-block; margin-bottom: 3px; padding: 1px 6px;
        border: 1px solid #fecaca; background: #fef2f2;
        font-size: 6.8px; font-weight: 800; color: #b91c1c;
        text-transform: uppercase; letter-spacing: .09em;
    }
    .doc-title h1 {
        margin: 0 0 4px; padding-bottom: 3px; border-bottom: 2px solid #b91c1c;
        font-size: 12px; font-weight: 800; color: #111827;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .doc-meta { border: 1px solid #e2e8f0; background: #f8fafc; }
    .doc-meta td { padding: 1.5px 5px; font-size: 7.6px; line-height: 1.3; }
    .doc-meta td.meta-label { width: 26%; color: #94a3b8; text-align: left; font-weight: 600; }
    .doc-meta td.meta-value { color: #1e293b; text-align: right; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
    th, td { border: 1px solid #cbd5e1; padding: 1px 6px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-size: 7.5px; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
    .section-head { background: #e2e8f0; font-weight: bold; font-size: 8.5px; }
    .label { width: 34%; color: #475569; }
    .cols-2 { width: 50%; }
    .spec-line { margin: 0; }
    .pass { color: #027a48; font-weight: bold; }
    .fail { color: #b42318; font-weight: bold; }
    .statement { padding: 3px 8px; line-height: 1.35; text-align: justify; }
    .extra-room { height: 20px; }
    .sign { width: 100%; margin-top: 3px; border: none; }
    .sign td { border: none; width: 50%; padding: 0 8px 0 0; vertical-align: top; }
    .sign-box { border: 1px solid #cbd5e1; }
    .sign-box th { background: #e2e8f0; }
    .sign-row { height: 26px; }
    .footer { margin-top: 6px; font-size: 7px; color: #64748b; text-align: center; }
    
    /* Single-page enforcement: prevent tables from splitting across pages */
    .section-head, table { page-break-inside: avoid; }
    .sign-box, .sign-row, .sign-grid, .sign-grid tr { page-break-inside: avoid; }
</style>
</head>
<body>
@php
    $logoPath = $document['company_logo'] ?? null;
    $logoUri = 'data:image/png;base64,' . base64_encode(file_get_contents(public_path($logoPath ?? 'images/mito-logo-pdf.png')));
@endphp
<div class="letterhead">
    <table>
        <tr>
            <td class="logo-cell">
                <img src="{{ $logoUri }}" alt="MITO Electronic">
            </td>
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
</div>

@yield('content')

<div class="footer">
    Dokumen ini dihasilkan otomatis oleh {{ config('app.name') }} pada {{ now()->translatedFormat('d M Y H:i') }} WIB.
</div>
</body>
</html>