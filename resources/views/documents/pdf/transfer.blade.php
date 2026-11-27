@extends('documents.pdf.layout')

@section('title', 'Berita Acara Transfer Aset IT')

@section('content')
@include('documents.pdf._form', ['variant' => 'transfer'])
@endsection

