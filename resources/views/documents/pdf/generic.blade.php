@extends('documents.pdf.layout')

@section('title', strtoupper($document['title']))

@section('content')
<table>
    <tr><th colspan="2">Informasi Aset</th></tr>
    <tr><td class="label">Asset ID</td><td>{{ $document['asset']['asset_id'] }}</td></tr>
    <tr><td class="label">Kategori</td><td>{{ $document['asset']['category'] ?? '-' }}</td></tr>
    <tr><td class="label">Merk / Model</td><td>{{ trim(($document['asset']['brand'] ?? '') . ' ' . ($document['asset']['model'] ?? '')) ?: '-' }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $document['asset']['serial_number'] ?? '-' }}</td></tr>
    <tr><td class="label">Hostname</td><td>{{ $document['asset']['hostname'] ?? '-' }}</td></tr>
    <tr><td class="label">Lokasi</td><td>{{ $document['location'] ?? '-' }}</td></tr>
    <tr><td class="label">Pengguna Saat Ini</td><td>{{ $document['current_user'] ?? '-' }}</td></tr>
</table>

@include('documents.pdf._parties')

@if($document['transfer'])
<table>
    <tr><th colspan="2">Detail Transfer</th></tr>
    <tr><td class="label">No. Transfer</td><td>{{ $document['transfer']['number'] }}</td></tr>
    <tr><td class="label">Dari</td><td>{{ $document['transfer']['from_user'] ?? '-' }}</td></tr>
    <tr><td class="label">Kepada</td><td>{{ $document['transfer']['to_user'] ?? '-' }}</td></tr>
    <tr><td class="label">Tanggal</td><td>{{ $document['transfer']['date'] ?? '-' }}</td></tr>
</table>
@endif
@endsection
