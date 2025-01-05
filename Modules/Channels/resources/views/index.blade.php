@extends('channels::layouts.master')

@section('content')
    <h1>Hello World</h1>

    <p>Module: {!! config('channels.name') !!}</p>
@endsection
