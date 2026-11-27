@extends('documents.pdf.layout')

@section('title', 'Berita Acara Pengembalian Aset IT')

@section('content')
@include('documents.pdf._form', ['variant' => 'return'])
@endsection

