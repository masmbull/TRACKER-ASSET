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
    .letterhead { border-bottom: 2px solid #1e40af; padding-bottom: 5px; margin-bottom: 6px; }
    .letterhead table { border: none; width: 100%; margin: 0; }
    .letterhead td { border: none; vertical-align: middle; padding: 0; }
    /* The brand block: a red tile holding the mark, sized to the mark's own
       aspect ratio. The old rule boxed an 800x200 canvas - mostly empty - at
       104px wide, so the wordmark rendered about 20px across inside a wide red
       bar, which is why the logo looked like a placeholder. The source image is
       now cropped to the mark and supersampled for print, so it is drawn at its
       real proportions and stays sharp at 96 dpi and above. */
    .logo-cell { width: 74px; padding: 5px 7px; background: #e11d2e; text-align: center; }
    .logo-cell img { width: 60px; height: auto; display: block; margin: 0 auto; image-rendering: auto; }
    .entities { padding-left: 11px; }
    .entities .legal { font-size: 8.5px; font-weight: bold; color: #0f172a; }
    .doc-title { text-align: right; }
    .doc-title h1 { font-size: 12px; margin: 0; color: #1e40af; text-transform: uppercase; }
    .doc-title .sub { font-size: 8px; color: #475569; margin-top: 1px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th, td { border: 1px solid #cbd5e1; padding: 2px 6px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-size: 7.5px; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
    .section-head { background: #e2e8f0; font-weight: bold; font-size: 8.5px; }
    .label { width: 34%; color: #475569; }
    .cols-2 { width: 50%; }
    .spec-line { margin: 0; }
    .pass { color: #027a48; font-weight: bold; }
    .fail { color: #b42318; font-weight: bold; }
    .statement { padding: 5px 8px; }
    .sign { width: 100%; margin-top: 3px; border: none; }
    .sign td { border: none; width: 50%; padding: 0 8px 0 0; vertical-align: top; }
    .sign-box { border: 1px solid #cbd5e1; }
    .sign-box th { background: #e2e8f0; }
    .sign-row { height: 26px; }
    .footer { margin-top: 6px; font-size: 7px; color: #64748b; text-align: center; }
    
    /* Single-page enforcement: prevent tables from splitting across pages */
    .section-head, table { page-break-inside: avoid; }
    .sign-box, .sign-row { page-break-inside: avoid; }
</style>
</head>
<body>
@php($logoUri = 'data:image/png;base64,' . base64_encode(file_get_contents(public_path('images/mito-logo-pdf.png'))))
<div class="letterhead">
    <table>
        <tr>
            <td class="logo-cell">
                <img src="{{ $logoUri }}" alt="MITO Electronic">
            </td>
            <td class="entities">
                @foreach((array) config('documents.entities', []) as $entity)
                    <div class="legal">{{ $entity }}</div>
                @endforeach
            </td>
            <td class="doc-title">
                <h1>@yield('title')</h1>
                <div class="sub">No. {{ $document['number'] }}</div>
                <div class="sub">Tanggal: {{ $document['date_long'] ?? $document['date'] }}</div>
                @if(filled($document['nomor_surat'] ?? null))
                    <div class="sub">Nomor Surat: {{ $document['nomor_surat'] }}</div>
                @endif
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