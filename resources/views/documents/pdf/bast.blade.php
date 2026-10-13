@extends('documents.pdf.layout')

@section('title', 'Berita Acara Serah Terima Aset IT')

@section('content')
@include('documents.pdf._form', ['variant' => 'bast'])
@endsection