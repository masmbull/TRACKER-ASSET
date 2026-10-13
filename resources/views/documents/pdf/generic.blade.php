@extends('documents.pdf.layout')

@section('title', strtoupper($document['title']))

@section('content')
@include('documents.pdf._asset', ['showAssignment' => true])

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
