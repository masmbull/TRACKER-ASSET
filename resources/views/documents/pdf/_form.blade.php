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

{{-- Signatures. F. Persetujuan on a transfer form, the closing block on a BAST. --}}
<table class="sign">
    <tr>
        <td>
            <table class="sign-box">
                <tr><th colspan="2">{{ $isBast ? 'PENERIMA ASET' : 'PENERIMA' }}</th></tr>
                <tr><td class="label">Nama</td><td>{{ $blank($sign['menerima'] ?? ($receiver['name'] ?? null)) }}</td></tr>
                @if($sign['signature_image'] ?? null && str_starts_with($sign['signature_image'], 'http'))
                    <tr><td class="label">Tanda Tangan</td><td><img src="{{ $sign['signature_image'] }}" style="max-width: 120px; max-height: 40px;"></td></tr>
                @elseif($sign['signature_image'] ?? null)
                    <tr><td class="label">Tanda Tangan</td><td><img src="data:image/{{ pathinfo($sign['signature_image'], PATHINFO_EXTENSION), JSON_UNESCAPED_URL }};base64,{{ base64_encode(file_get_contents(storage_path($sign['signature_image'])))" style="max-width: 120px; max-height: 40px;"></td></tr>
                @else
                    <tr><td class="label">Tanda Tangan</td><td class="sign-row"></td></tr>
                @endif
                <tr><td class="label">Tanggal</td><td>{{ $blank($document['signature_date'] ?? null) }}</td></tr>
            </table>
        </td>
        <td>
            <table class="sign-box">
                <tr><th colspan="2">{{ $isBast ? 'PENYERAH ASET (IT)' : 'PENYERAH' }}</th></tr>
                <tr><td class="label">Nama</td><td>{{ $blank($sign['menyerahkan'] ?? ($sender['name'] ?? null)) }}</td></tr>
                @if($sign['signature_image'] ?? null && str_starts_with($sign['signature_image'], 'http'))
                    <tr><td class="label">Tanda Tangan</td><td><img src="{{ $sign['signature_image'] }}" style="max-width: 120px; max-height: 40px;"></td></tr>
                @elseif($sign['signature_image'] ?? null)
                    <tr><td class="label">Tanda Tangan</td><td><img src="data:image/{{ pathinfo($sign['signature_image'], PATHINFO_EXTENSION), JSON_UNESCAPED_URL }};base64,{{ base64_encode(file_get_contents(storage_path($sign['signature_image'])))" style="max-width: 120px; max-height: 40px;"></td></tr>
                @else
                    <tr><td class="label">Tanda Tangan</td><td class="sign-row"></td></tr>
                @endif
                <tr><td class="label">Tanggal</td><td>{{ $blank($document['signature_date'] ?? null) }}</td></tr>
            </table>
        </td>
    </tr>
</table>