@extends('documents.pdf.layout')

@section('title', 'FORM ACCEPTANCE TEST (FAT)')

@section('content')
<p class="lead">Dengan ini dilakukan pemeriksaan penerimaan (acceptance test) terhadap aset berikut:</p>

<table>
    <tr><th colspan="2">Informasi Aset</th></tr>
    <tr><td class="label">Asset ID</td><td>{{ $document['asset']['asset_id'] }}</td></tr>
    <tr><td class="label">Kategori</td><td>{{ $document['asset']['category'] ?? '-' }}</td></tr>
    <tr><td class="label">Merk / Model</td><td>{{ trim(($document['asset']['brand'] ?? '') . ' ' . ($document['asset']['model'] ?? '')) ?: '-' }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $document['asset']['serial_number'] ?? '-' }}</td></tr>
    <tr><td class="label">Hostname</td><td>{{ $document['asset']['hostname'] ?? '-' }}</td></tr>
    <tr><td class="label">Spesifikasi</td><td>{{ collect([$document['asset']['processor'] ?? null, $document['asset']['ram'] ?? null, $document['asset']['storage'] ?? null, $document['asset']['os_version'] ?? null])->filter()->implode(' / ') ?: '-' }}</td></tr>
    <tr><td class="label">Lokasi</td><td>{{ $document['location'] ?? '-' }}</td></tr>
    <tr><td class="label">Pengguna Saat Ini</td><td>{{ $document['current_user'] ?? '-' }}</td></tr>
</table>

@include('documents.pdf._parties')

@if($document['transfer'])
<table>
    <tr><th colspan="2">Referensi Transfer</th></tr>
    <tr><td class="label">No. Transfer</td><td>{{ $document['transfer']['number'] }}</td></tr>
    <tr><td class="label">Tanggal</td><td>{{ $document['transfer']['date'] ?? '-' }}</td></tr>
    <tr><td class="label">Dari</td><td>{{ $document['transfer']['from_user'] ?? '-' }} ({{ $document['transfer']['from_location'] ?? '-' }})</td></tr>
    <tr><td class="label">Kepada</td><td>{{ $document['transfer']['to_user'] ?? '-' }} ({{ $document['transfer']['to_location'] ?? '-' }})</td></tr>
    <tr><td class="label">Kondisi / Aksesoris</td><td>{{ $document['transfer']['condition'] ?? '-' }} / {{ $document['transfer']['accessories'] ?? '-' }}</td></tr>
</table>
@endif

@if(!empty($document['checklist']))
<table>
    <tr><th>Item Pemeriksaan</th><th style="width:20%">Hasil</th></tr>
    @foreach($document['checklist'] as $check)
        <tr>
            <td>{{ $check['item'] }}</td>
            <td>{{ ['pass' => 'PASS', 'fail' => 'FAIL', 'na' => 'N/A'][$check['result']] ?? 'N/A' }}</td>
        </tr>
    @endforeach
</table>
@endif
@endsection
