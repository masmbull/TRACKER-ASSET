@extends('documents.pdf.layout')

@section('title', 'BERITA ACARA SERAH TERIMA (BAST)')

@section('content')
<p class="lead">
    Pada tanggal {{ $document['date'] }}, telah dilakukan serah terima aset
    dari pihak yang menyerahkan kepada pihak yang menerima, dengan perincian sebagai berikut:
</p>

<table>
    <tr><th colspan="2">Informasi Aset</th></tr>
    <tr><td class="label">Asset ID</td><td>{{ $document['asset']['asset_id'] }}</td></tr>
    <tr><td class="label">Kategori</td><td>{{ $document['asset']['category'] ?? '-' }}</td></tr>
    <tr><td class="label">Merk / Model</td><td>{{ trim(($document['asset']['brand'] ?? '') . ' ' . ($document['asset']['model'] ?? '')) ?: '-' }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $document['asset']['serial_number'] ?? '-' }}</td></tr>
    <tr><td class="label">Hostname</td><td>{{ $document['asset']['hostname'] ?? '-' }}</td></tr>
    <tr><td class="label">Spesifikasi</td><td>{{ collect([$document['asset']['processor'] ?? null, $document['asset']['ram'] ?? null, $document['asset']['storage'] ?? null, $document['asset']['os_version'] ?? null])->filter()->implode(' / ') ?: '-' }}</td></tr>
</table>

@include('documents.pdf._parties')

<table>
    <tr><th colspan="2">Serah Terima</th></tr>
    <tr><td class="label">Dari</td><td>{{ $document['transfer']['from_user'] ?? '-' }} ({{ $document['transfer']['from_department'] ?? '-' }} / {{ $document['transfer']['from_location'] ?? '-' }})</td></tr>
    <tr><td class="label">Kepada</td><td>{{ $document['transfer']['to_user'] ?? ($document['current_user'] ?? '-') }} ({{ $document['transfer']['to_department'] ?? '-' }} / {{ $document['transfer']['to_location'] ?? ($document['location'] ?? '-') }})</td></tr>
    <tr><td class="label">Tanggal</td><td>{{ $document['transfer']['date'] ?? $document['date'] }}</td></tr>
    <tr><td class="label">Kondisi</td><td>{{ $document['transfer']['condition'] ?? '-' }}</td></tr>
    <tr><td class="label">Aksesoris</td><td>{{ $document['transfer']['accessories'] ?? '-' }}</td></tr>
</table>

<p>Dengan ditandatanganinya berita acara ini, kedua belah pihak menyatakan bahwa aset telah diserahkan dan diterima dalam kondisi sebagaimana tercantum di atas, dan segala hak pakai aset berpindah kepada pihak yang menerima.</p>
@endsection
