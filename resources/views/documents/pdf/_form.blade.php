{{--
    Body of every generated asset document.

    Two people, not four. The office's paper form names the sender and receiver
    once each; the old renderer printed a second differently-shaped party table
    on top of it. The role order and section letters follow the sheet for each
    variant, and the shared visual language lives in layout.blade.php so this
    partial only decides *what* is printed, never how it looks.
--}}
@php
    $variant = $variant ?? 'fat';

    $parties = $document['parties'] ?? [];
    $sender = (array) ($parties['sender'] ?? []);
    $receiver = (array) ($parties['receiver'] ?? []);
    $asset = $document['asset'] ?? [];
    $handover = $document['handover'] ?? [];
    $sign = $document['signatories'] ?? [];
    $kelengkapan = (array) ($document['kelengkapan'] ?? []);
    $kelengkapanDetail = (array) ($document['kelengkapan_detail'] ?? []);
    $copyView = (array) ($copy ?? []);

    $blank = static fn ($v) => filled($v) ? $v : '-';

    // [heading, name label, party data, asset letter, time letter, statement letter]
    $roles = [
        'fat' => [
            ['A. INFORMASI YANG MENYERAHKAN', 'Nama Penyerah', $sender],
            ['B. INFORMASI YANG MENERIMA', 'Nama Penerima', $receiver],
            'C', 'D', 'E',
        ],
        'bast' => [
            ['A. INFORMASI PENERIMA ASET', 'Nama Karyawan', $receiver],
            ['B. INFORMASI PENYERAH ASET (IT)', 'Nama PIC IT', $sender],
            'D', 'E', 'F',
        ],
        'assignment' => [
            ['A. INFORMASI YANG MENYERAHKAN', 'Nama Penyerah', $sender],
            ['B. INFORMASI PENERIMA', 'Nama Penerima', $receiver],
            'C', 'D', 'E',
        ],
        'repair' => [
            ['A. USER / PEMILIK PERANGKAT', 'Nama User', $receiver],
            ['B. PENERIMA PERANGKAT (IT)', 'Nama PIC IT', $sender],
            'C', 'D', 'E',
        ],
        'disposal' => [
            ['A. PIHAK PENGAJU', 'Nama Pengaju', $sender],
            ['B. PIHAK PENERIMA / VERIFIKATOR', 'Nama Verifikator', $receiver],
            'C', 'D', 'E',
        ],
    ];

    [$firstRole, $secondRole, $assetLetter, $timeLetter, $statementLetter] = $roles[$variant] ?? $roles['fat'];
@endphp

@foreach([$firstRole, $secondRole] as [$heading, $nameLabel, $party])
<table class="form">
    <tr><th class="section-head" colspan="2">{{ $heading }}</th></tr>
    <tr><td class="label">{{ $nameLabel }}</td><td>{{ $blank($party['name'] ?? null) }}</td></tr>
    <tr><td class="label">NIK / ID Karyawan</td><td>{{ $blank($party['employee_id'] ?? null) }}</td></tr>
    <tr><td class="label">Entitas / Cabang</td><td>{{ $blank($party['entity'] ?? null) }}</td></tr>
    <tr><td class="label">Divisi / Jabatan</td><td>{{ $blank($party['division'] ?? null) }}</td></tr>
    <tr><td class="label">Email</td><td>{{ $blank($party['email'] ?? null) }}</td></tr>
    <tr><td class="label">No. Telepon</td><td>{{ $blank($party['phone'] ?? null) }}</td></tr>
</table>
@endforeach

{{-- Asset identity and specification --}}
<table class="form">
    <tr><th class="section-head" colspan="2">{{ $assetLetter }}. INFORMASI ASET</th></tr>
    <tr><td class="label">Jenis Aset</td><td>{{ $blank(($asset['type'] ?? null) ?: ($asset['category'] ?? null)) }}</td></tr>
    <tr><td class="label">Brand &amp; Model</td><td>{{ $blank($asset['brand_model'] ?? null) }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $blank($asset['serial'] ?? null) }}</td></tr>
    <tr><td class="label">Asset Tag / Inventory Code</td><td>{{ $blank($asset['tag'] ?? null) }}</td></tr>
    <tr><td class="label">Processor</td><td>{{ $blank($asset['processor_detail'] ?? null) }}</td></tr>
    <tr><td class="label">RAM</td><td>{{ $blank($asset['ram_detail'] ?? null) }}</td></tr>
    <tr><td class="label">Storage / Kapasitas</td><td>{{ $blank($asset['storage_detail'] ?? null) }}</td></tr>
    @if(filled($asset['os_version'] ?? null))
        <tr><td class="label">Sistem Operasi</td><td>{{ $asset['os_version'] }}</td></tr>
    @endif
    @if(filled($document['notes'] ?? null))
        <tr><td class="label">Catatan</td><td>{{ $document['notes'] }}</td></tr>
    @endif
</table>

{{-- Physical completeness, two items per row to keep the sheet to one page --}}
@if($kelengkapan)
<table class="form">
    <tr><th class="section-head" colspan="4">{{ $assetLetter }}. KELENGKAPAN ASET</th></tr>
    @foreach(collect($kelengkapan)->chunk(2) as $chunk)
        <tr>
            @foreach($chunk as $item => $answer)
                <td class="label">{{ $item }}</td>
                <td>
                    {{ $answer === 'ada' ? 'Ada' : ($answer === 'tidak' ? 'Tidak Ada' : '-') }}
                    @if($answer === 'ada' && filled($kelengkapanDetail[$item] ?? null))
                        <span class="detail">— {{ $kelengkapanDetail[$item] }}</span>
                    @endif
                </td>
            @endforeach
            @if($chunk->count() === 1)
                <td class="label"></td><td></td>
            @endif
        </tr>
    @endforeach
</table>
@endif

{{-- Acceptance / setup / condition checklist, two per row --}}
@if(!empty($document['checklist']))
<table class="form">
    <tr><th class="section-head" colspan="4">CHECKLIST {{ strtoupper($variant) }}</th></tr>
    @foreach(collect($document['checklist'])->chunk(2) as $chunk)
        <tr>
            @foreach($chunk as $check)
                @php($result = $check['result'] ?? 'na')
                <td>{{ $check['item'] }}</td>
                <td class="check-cell {{ $result === 'fail' ? 'fail' : ($result === 'pass' ? 'pass' : '') }}">
                    {{ ['pass' => 'YA / PASS', 'fail' => 'TIDAK / FAIL', 'na' => 'N/A'][$result] ?? 'N/A' }}
                </td>
            @endforeach
            @if($chunk->count() === 1)
                <td></td><td></td>
            @endif
        </tr>
    @endforeach
</table>
@endif

{{-- Timing --}}
<table class="form">
    <tr><th class="section-head" colspan="2">{{ $timeLetter }}. WAKTU</th></tr>
    <tr><td class="label">{{ $copyView['handover_date'] ?? 'Tanggal Penyerahan' }}</td><td>{{ $blank($handover['date_long'] ?? ($handover['date'] ?? null)) }}</td></tr>
    <tr><td class="label">{{ $copyView['received_date'] ?? 'Tanggal Diterima' }}</td><td>{{ $blank($handover['received_long'] ?? ($handover['received_date'] ?? null)) }}</td></tr>
    <tr><td class="label">Keperluan</td><td>{{ $blank($handover['purpose'] ?? null) }}</td></tr>
    @if(filled($document['location'] ?? null))
        <tr><td class="label">Lokasi</td><td>{{ $document['location'] }}</td></tr>
    @endif
</table>

{{-- Statement / legal clause --}}
<table class="form">
    <tr><th class="section-head" colspan="2">{{ $statementLetter }}. PERNYATAAN / KLAUSUL</th></tr>
    <tr><td class="statement" colspan="2">{{ $document['statement'] ?? ($copyView['statement'] ?? '-') }}</td></tr>
</table>

{{-- Signatures --}}
<table class="sign-grid">
    <tr>
        <th colspan="2">Diperiksa dan Diterima Oleh</th>
    </tr>
    <tr>
        <td>
            <div class="sign-cell-role">User / Penerima Aset</div>
            @if(!empty($document['receiver_signature_image_data']))
                <img class="sign-cell-image" src="{{ $document['receiver_signature_image_data'] }}" alt="">
            @else
                <div class="sign-cell-dashed"></div>
            @endif
            <div class="sign-cell-name">{{ $blank($sign['menerima'] ?? null) }}</div>
            <div class="sign-cell-date">{{ $blank($sign['menerima_date'] ?? null) }}</div>
        </td>
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
