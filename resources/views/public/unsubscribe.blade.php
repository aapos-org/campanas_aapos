@extends('layouts.public')

@section('title', 'Darse de baja')

@section('content')
    @if ($done)
        <h1>Te diste de baja</h1>
        <p role="status">Ya no recibirás correos de «{{ $audience->name }}». Sentimos verte partir.</p>
    @else
        <h1>¿Darte de baja?</h1>
        <p>Dejarás de recibir los correos de «{{ $audience->name }}» de {{ $organization->name }} en {{ $contact->email }}.</p>
        <form method="post" action="{{ $actionUrl }}">
            @csrf
            <button type="submit">Confirmar la baja</button>
        </form>
    @endif
    <p class="muted" style="margin-top:16px"><a href="{{ $preferencesUrl }}">Administrar todas mis suscripciones</a></p>
@endsection
