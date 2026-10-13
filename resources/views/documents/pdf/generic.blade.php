@extends('documents.pdf.layout')

@php
    // The generic template covers TRANSFER, RETURN and ASSIGNMENT - the types
    // the paper form was not written for. Its heading comes from the document's
    // own title (the register's label for the type), not a fixed string, so a
    // Return does not arrive headed "Form Transfer Fixed Asset IT".
    $heading = config('documents.labels.'.$document['type'] ?? '', $document['title'] ?? 'Dokumen Aset');
@endphp

@section('title', $heading)

@section('content')
@include('documents.pdf._form', ['variant' => 'fat'])
@endsection