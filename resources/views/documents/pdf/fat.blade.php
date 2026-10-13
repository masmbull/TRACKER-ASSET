@extends('documents.pdf.layout')

@section('title', 'Form Transfer Fixed Asset IT')

@section('content')
@include('documents.pdf._form', ['variant' => 'fat'])
@endsection