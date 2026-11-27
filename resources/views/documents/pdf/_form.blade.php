{{--
    Shared body of every generated asset document.

    The office's official sheets (FAT SERAH/TERIMA and BAST BARU) are sectioned
    A-F; the wider IT document library (transfer, handover, return, assignment,
    repair, disposal) uses the same skeleton with different party roles, section
    letters and checklists. One partial keeps those differences in one table so
    a document type cannot drift into another's wording.

    @param string $variant  fat|bast|transfer|handover|return|assignment|repair|disposal
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
    $copyView = (array) ($copy ?? []);

    $blank = static fn ($v) => filled($v) ? $v : '-';

    // Which party is introduced first, and how each is named on the sheet.
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
        'transfer' => [
            ['A. PIHAK PENYERAH', 'Nama Penyerah', $sender],
            ['B. PIHAK PENERIMA', 'Nama Penerima', $receiver],
            'C', 'D', 'E',
        ],
        'handover' => [
            ['A. INFORMASI KARYAWAN / PENERIMA', 'Nama Karyawan', $receiver],
            ['B. INFORMASI PENYERAH (IT)', 'Nama PIC IT', $sender],
            'D', 'E', 'F',
        ],
        'return' => [
            ['A. PIHAK PENGEMBALI', 'Nama Pemegang', $sender],
            ['B. PIHAK PENERIMA (IT)', 'Nama PIC IT', $receiver],
            'C', 'D', 'E',
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
<table>
    <tr><th class="section-head" colspan="2">{{ $heading }}</th></tr>
    <tr><td class="label">{{ $nameLabel }}</td><td>{{ $blank($party['name'] ?? null) }}</td></tr>
    <tr><td class="label">NIK / ID Karyawan</td><td>{{ $blank($party['employee_id'] ?? null) }}</td></tr>
    <tr><td class="label">Entitas / Cabang</td><td>{{ $blank($party['entity'] ?? null) }}</td></tr>
    <tr><td class="label">Divisi / Jabatan</td><td>{{ $blank($party['division'] ?? null) }}</td></tr>
    <tr><td class="label">Email</td><td>{{ $blank($party['email'] ?? null) }}</td></tr>
    <tr><td class="label">No. Telepon</td><td>{{ $blank($party['phone'] ?? null) }}</td></tr>
</table>
@endforeach

{{-- Asset identity + specification --}}
<table>
    <tr><th class="section-head" colspan="2">{{ $assetLetter }}. INFORMASI ASET</th></tr>
    <tr><td class="label">Jenis Aset</td><td>{{ $blank(($asset['type'] ?? null) ?: ($asset['category'] ?? null)) }}</td></tr>
    <tr><td class="label">Brand &amp; Model</td><td>{{ $blank($asset['brand_model'] ?? null) }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $blank($asset['serial'] ?? null) }}</td></tr>
    <tr><td class="label">Asset Tag / Inventory Code</td><td>{{ $blank($asset['tag'] ?? null) }}</td></tr>
    <tr><td class="label">Processor</td><td>{{ $blank($asset['processor_detail'] ?? null) }}</td></tr>
    <tr><td class="label">RAM</td><td>{{ $blank($asset['ram_detail'] ?? null) }}</td></tr>
    <tr><td class="label">Storage</td><td>{{ $blank($asset['storage_detail'] ?? null) }}</td></tr>
    @if(filled($asset['os_version'] ?? null))
        <tr><td class="label">Sistem Operasi</td><td>{{ $asset['os_version'] }}</td></tr>
    @endif
    @if(filled($document['notes'] ?? null))
        <tr><td class="label">Catatan</td><td>{{ $document['notes'] }}</td></tr>
    @endif
    <tr><td class="label">Lain-Lain</td><td class="extra-room"></td></tr>
</table>

{{-- Physical completeness, two items per row to keep the sheet compact --}}
@if($kelengkapan)
<table>
    <tr><th class="section-head" colspan="4">{{ $assetLetter }}. KELENGKAPAN ASET</th></tr>
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

{{-- Type-specific acceptance / setup / condition checklist --}}
@if(!empty($document['checklist']))
<table>
    <tr><th class="section-head" colspan="4">CHECKLIST {{ strtoupper($variant) }}</th></tr>
    @foreach(collect($document['checklist'])->chunk(2) as $chunk)
        <tr>
            @foreach($chunk as $check)
                @php($result = $check['result'] ?? 'na')
                <td>{{ $check['item'] }}</td>
                <td class="{{ $result === 'fail' ? 'fail' : ($result === 'pass' ? 'pass' : '') }}" style="width:14%">
                    {{ ['pass' => 'YA / PASS', 'fail' => 'TIDAK / FAIL', 'na' => 'N/A'][$result] ?? 'N/A' }}
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

{{-- Timing --}}
<table>
    <tr><th class="section-head" colspan="2">{{ $timeLetter }}. WAKTU</th></tr>
    <tr><td class="label">{{ $copyView['handover_date'] ?? 'Tanggal Penyerahan' }}</td><td>{{ $blank($handover['date_long'] ?? ($handover['date'] ?? null)) }}</td></tr>
    <tr><td class="label">{{ $copyView['received_date'] ?? 'Tanggal Diterima' }}</td><td>{{ $blank($handover['received_long'] ?? ($handover['received_date'] ?? null)) }}</td></tr>
    <tr><td class="label">Keperluan</td><td>{{ $blank($handover['purpose'] ?? null) }}</td></tr>
    @if(filled($document['location'] ?? null))
        <tr><td class="label">Lokasi</td><td>{{ $document['location'] }}</td></tr>
    @endif
</table>

{{-- Statement / legal clause --}}
<table>
    <tr><th class="section-head">{{ $statementLetter }}. PERNYATAAN / KLAUSUL</th></tr>
    <tr><td class="statement">{{ $document['statement'] ?? ($copyView['statement'] ?? '-') }}</td></tr>
</table>

{{-- Signatures: typed names printed under the line, wet signatures as images --}}
<style>
    .sign-grid { width: 100%; border-collapse: collapse; margin-top: 3px; }
    .sign-grid th { border: 1px solid #9ca3af; background: #e2e8f0; font-weight: bold; text-align: center; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #334155; padding: 3px 6px; }
    .sign-grid td { border: 1px solid #9ca3af; width: 50%; height: 72px; vertical-align: top; padding: 5px; }
    .sign-cell-role { font-size: 8px; font-weight: 700; color: #344054; margin-bottom: 2px; }
    .sign-cell-dashed { border-bottom: 1px dashed #6b7280; margin: 3px 0; height: 1px; }
    .sign-cell-name { font-size: 8.5px; font-weight: 700; text-align: center; margin-top: 2px; color: #111827; }
    .sign-cell-date { font-size: 7px; color: #475569; text-align: center; }
    .sign-cell-image { display: block; height: 30px; max-width: 100%; margin: 2px auto; }
</style>
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
