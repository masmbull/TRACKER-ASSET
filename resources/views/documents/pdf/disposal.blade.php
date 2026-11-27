@extends('documents.pdf.layout')

@section('title', 'Berita Acara Penghapusan Aset IT')

@section('content')
@include('documents.pdf._form', ['variant' => 'disposal'])
@endsection

