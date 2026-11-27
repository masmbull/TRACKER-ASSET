@extends('documents.pdf.layout')

@php
    // Fallback for any future type added to config without its own wrapper.
    // The heading comes from the register's label, and the body follows that
    // type's own role map rather than always falling back to FAT's ordering.
    $type = strtolower($document['type'] ?? '');
    $heading = config('documents.labels.'.$document['type'] ?? '', $document['title'] ?? 'Dokumen Aset');
    // Legacy TRANSFER/HANDOVER/RETURN documents render through FAT: they were
    // the same serah-terima event before the types were consolidated.
    $variant = in_array($type, ['fat', 'bast', 'assignment', 'repair', 'disposal'], true)
        ? $type
        : 'fat';
@endphp

@section('title', $heading)

@section('content')
@include('documents.pdf._form', ['variant' => $variant])
@endsection
