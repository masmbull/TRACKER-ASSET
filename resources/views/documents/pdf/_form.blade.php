{{--
    Sections A–F of the official paper form, rendered as a generated document.

    One partial, shared by FAT, BAST and generic documents, because all of them
    reproduce the same sheet: the sender, the receiver, the asset (with its
    specification and completeness), when it was handed over, the statement, and
    the two signature blocks. Copying this into three templates is how FAT and
    BAST drifted apart - one grew a row the other never got, and there was no
    single place that represented "the form".

    Two people, not four. The old documents printed a "Penghantar/Penerima" dump
    of raw English key:value pairs and ISO dates on top of a second,
    differently-shaped handover table - the same facts twice, in two vocabularies,
    neither reading like the sheet people sign. Here the parties appear once, in
    the form's own words and order.

    The two official forms genuinely differ, so the partial does too, off $variant:
      - fat  (sheets SERAH/TERIMA): A Yang Menyerahkan, B Yang Menerima,
             C Aset, D Waktu Penyerahan, E Pernyataan, F Persetujuan.
      - bast (sheet "Template BAST - BARU"): receiver first, IT as the giver,
             then the asset, completeness, time, and the long klausul.

    @param string $variant  'fat' (default) or 'bast'.
--}}
@php
    $variant = $variant ?? 'fat';
    $isBast = $variant === 'bast';

    $parties = $document['parties'] ?? [];
    $sender = (array) ($parties['sender'] ?? []);
    $receiver = (array) ($parties['receiver'] ?? []);
    $asset = $document['asset'] ?? [];
    $handover = $document['handover'] ?? [];
    $sign = $document['signatories'] ?? [];
    $kelengkapan = (array) ($document['kelengkapan'] ?? []);

    $blank = static fn ($v) => filled($v) ? $v : '-';

    // The form puts the employee it went to first on a BAST, and the IT giver
    // first on a transfer form. Same two blocks, ordered as the sheet orders them.
    $firstRole = $isBast
        ? ['A. INFORMASI PENERIMA ASET', $receiver]
        : ['A. INFORMASI YANG MENYERAHKAN', $sender];
    $secondRole = $isBast
        ? ['B. INFORMASI PENYERAH ASET (IT)', $sender]
        : ['B. INFORMASI YANG MENERIMA', $receiver];
    $nameLabel = $isBast ? 'Nama Karyawan' : null;
@endphp

@foreach([$firstRole, $secondRole] as [$heading, $party])
<table>
    <tr><th class="section-head" colspan="2">{{ $heading }}</th></tr>
    @if($nameLabel)
        <tr><td class="label">{{ $loop->first ? $nameLabel : 'Nama PIC IT' }}</td><td>{{ $blank($party['name'] ?? null) }}</td></tr>
    @else
        <tr><td class="label">Nama Penyerah / Penerima</td><td>{{ $blank($party['name'] ?? null) }}</td></tr>
    @endif
    <tr><td class="label">NIK / ID Karyawan</td><td>{{ $blank($party['employee_id'] ?? null) }}</td></tr>
    <tr><td class="label">Entitas</td><td>{{ $blank($party['entity'] ?? null) }}</td></tr>
    <tr><td class="label">Divisi / Jabatan</td><td>{{ $blank($party['division'] ?? null) }}</td></tr>
    <tr><td class="label">Email</td><td>{{ $blank($party['email'] ?? null) }}</td></tr>
    <tr><td class="label">No. Telepon</td><td>{{ $blank($party['phone'] ?? null) }}</td></tr>
</table>
@endforeach

{{-- C. Informasi Aset --}}
<table>
    <tr><th class="section-head" colspan="2">C. INFORMASI ASET</th></tr>
    <tr><td class="label">Jenis Aset</td><td>{{ $blank(($asset['type'] ?? null) ?: ($asset['category'] ?? null)) }}</td></tr>
    <tr><td class="label">Brand &amp; Model</td><td>{{ $blank($asset['brand_model'] ?? null) }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $blank($asset['serial'] ?? null) }}</td></tr>
    <tr><td class="label">Asset Tag / Inventory Code</td><td>{{ $blank($asset['tag'] ?? null) }}</td></tr>
    <tr><td class="label">Processor</td><td>{{ $blank($asset['processor_detail'] ?? null) }}</td></tr>
    <tr><td class="label">RAM</td><td>{{ $blank($asset['ram_detail'] ?? null) }}</td></tr>
    <tr><td class="label">Storage</td><td>{{ $blank($asset['storage_detail'] ?? null) }}</td></tr>
    @if(filled($asset['storage_type_detail'] ?? null))
        <tr><td class="label">Storage Type</td><td>{{ $asset['storage_type_detail'] }}</td></tr>
    @endif
    @if(filled($asset['storage_capacity_detail'] ?? null))
        <tr><td class="label">Storage Capacity</td><td>{{ $asset['storage_capacity_detail'] }}</td></tr>
    @endif
    @if(filled($asset['os_version'] ?? null))
        <tr><td class="label">Sistem Operasi</td><td>{{ $asset['os_version'] }}</td></tr>
    @endif
    @if(filled($document['notes'] ?? null))
        <tr><td class="label">Catatan</td><td>{{ $document['notes'] }}</td></tr>
    @endif
    <tr><td class="label">Lain-Lain</td><td class="extra-room"></td></tr>
</table>

{{-- Completeness, item by item, exactly as the paper form asks.
     Two items per row: the paper form is one page and a full-width row per
     accessory is what pushed the signature block onto a second sheet. --}}
@if($kelengkapan)
<table>
    <tr><th class="section-head" colspan="4">{{ $isBast ? 'D' : 'C' }}. KELENGKAPAN ASSET</th></tr>
    @foreach(collect($kelengkapan)->chunk(2) as $chunk)
        <tr>
            @foreach($chunk as $item => $answer)
                <td class="label">{{ $item }}</td>
                <td>{{ $answer === 'ada' ? 'Ada' : ($answer === 'tidak' ? 'Tidak Ada' : '-') }}</td>
            @endforeach
            @if($chunk->count() === 1)
                <td class="label"></td><td></td>
            @endif
        </tr>
    @endforeach
</table>
@endif

{{-- Checklist FAT, when this type has one. Also two per row for height. --}}
@if(!empty($document['checklist']))
<table>
    <tr><th class="section-head" colspan="4">CHECKLIST PENERIMAAN</th></tr>
    @foreach(collect($document['checklist'])->chunk(2) as $chunk)
        <tr>
            @foreach($chunk as $check)
                @php($result = $check['result'] ?? 'na')
                <td>{{ $check['item'] }}</td>
                <td class="{{ $result === 'fail' ? 'fail' : ($result === 'pass' ? 'pass' : '') }}" style="width:14%">
                    {{ ['pass' => 'PASS', 'fail' => 'FAIL', 'na' => 'N/A'][$result] ?? 'N/A' }}
                </td>
            @endforeach
            @if($chunk->count() === 1)
                <td></td><td></td>
            @endif
        </tr>
    @endforeach
    <tr><td class="label">Lain-Lain</td><td colspan="3" class="extra-room"></td></tr>
</table>
@endif

{{-- Waktu Penyerahan --}}
<table>
    <tr><th class="section-head" colspan="2">{{ $isBast ? 'E' : 'D' }}. WAKTU PENYERAHAN</th></tr>
    <tr><td class="label">{{ $copy['handover_date'] ?? 'Tanggal Penyerahan' }}</td><td>{{ $blank($handover['date_long'] ?? ($handover['date'] ?? null)) }}</td></tr>
    @unless($isBast)
        <tr><td class="label">{{ $copy['received_date'] ?? 'Tanggal Diterima' }}</td><td>{{ $blank($handover['received_long'] ?? ($handover['received_date'] ?? null)) }}</td></tr>
    @endunless
    <tr><td class="label">Keperluan</td><td>{{ $blank($handover['purpose'] ?? null) }}</td></tr>
    @if($isBast)
        <tr><td class="label">Lokasi Penyerahan</td><td>{{ $blank($document['location'] ?? null) }}</td></tr>
    @endif
</table>

{{-- Statement / klausul --}}
<table>
    <tr><th class="section-head">{{ $isBast ? 'F. KLAUSUL' : 'E. PERNYATAAN' }}</th></tr>
    <tr><td class="statement">{{ $document['statement'] ?? ($copy['statement'] ?? '-') }}</td></tr>
</table>

 {{-- Signatures. Satu kolom: "Diperiksa dan Diterima Oleh". USER dan IT
     masing-masing punya area tanda tangan, dengan nama tercetak di bawahnya. --}}
<style>
    .sign-grid { width: 100%; border-collapse: collapse; margin-top: 3px; }
    .sign-grid th { border: 1px solid #9ca3af; background: #e2e8f0; font-weight: bold; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #334155; padding: 3px 6px; }
    .sign-grid td { border: 1px solid #9ca3af; height: 56px; vertical-align: top; padding: 4px; }
    .sign-cell-role { font-size: 8px; font-weight: 700; color: #344054; margin-bottom: 2px; }
    .sign-cell-dashed { border-bottom: 1px dashed #6b7280; margin: 3px 0; height: 1px; }
    .sign-cell-name { font-size: 8.5px; font-weight: 700; text-align: center; margin-top: 2px; color: #111827; }
    .sign-cell-date { font-size: 7px; color: #475569; text-align: center; }
    .sign-cell-image { display: block; height: 30px; max-width: 100%; margin: 2px auto; }
</style>
<table class="sign-grid">
    <tr>
        <th>Diperiksa dan Diterima Oleh</th>
    </tr>
    <tr>
        <td>
            <div class="sign-cell-role">User / Penerima Aset</div>
            <div class="sign-cell-dashed"></div>
            <div class="sign-cell-name">{{ $blank($sign['menerima'] ?? null) }}</div>
            <div class="sign-cell-date">{{ $blank($sign['menerima_date'] ?? null) }}</div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="sign-cell-role">IT / Penyerah Aset</div>
            @if(!empty($document['signature_image_data']))
                <img class="sign-cell-image" src="{{ $document['signature_image_data'] }}" alt="">
            @else
                <div class="sign-cell-dashed"></div>
            @endif
            <div class="sign-cell-name">{{ $blank($sign['it'] ?? null) }}</div>
            <div class="sign-cell-date">{{ $blank($sign['it_date'] ?? null) }}</div>
        </td>
    </tr>
</table>