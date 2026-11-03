@extends('emails.layouts.master', ['hideDefaultContactNote' => true, 'hide_default_contact_note' => true])

@php
    $hideDefaultContactNote = true;
    $hide_default_contact_note = true;
@endphp

@section('content')
    {!! $content !!}
@endsection
