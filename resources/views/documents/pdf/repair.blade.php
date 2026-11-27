@extends('documents.pdf.layout')

@section('title', 'Form Serah Terima Perangkat untuk Perbaikan')

@section('content')
@include('documents.pdf._form', ['variant' => 'repair'])
@endsection

