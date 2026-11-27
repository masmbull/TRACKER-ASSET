@extends('documents.pdf.layout')

@section('title', 'Form Serah Terima Aset IT (Handover)')

@section('content')
@include('documents.pdf._form', ['variant' => 'handover'])
@endsection

