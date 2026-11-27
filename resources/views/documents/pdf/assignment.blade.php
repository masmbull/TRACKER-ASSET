@extends('documents.pdf.layout')

@section('title', 'Form Penyerahan Aset IT')

@section('content')
@include('documents.pdf._form', ['variant' => 'assignment'])
@endsection

