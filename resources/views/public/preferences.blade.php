@extends('layouts.public')

@section('title', 'Preferencias de suscripción')

@section('content')
    <h1>Tus suscripciones</h1>
    <p class="muted">{{ $contact->email }} · {{ $organization->name }}</p>

    @if ($done)
        <div class="ok" role="status">Tus preferencias se guardaron.</div>
    @endif

    @forelse ($subscriptions as $subscription)
        <div class="row">
            <div>
                <strong>{{ $subscription->audience->name }}</strong><br>
                <span class="muted">{{ $subscription->status->label() }}</span>
            </div>
            @if (! $subscription->status->isTerminal() && $subscription->status->value !== 'non_subscribed')
                <form method="post" action="{{ $actionUrl }}">
                    @csrf
                    <input type="hidden" name="audience" value="{{ $subscription->audience_id }}">
                    <button type="submit" class="secondary">Darme de baja</button>
                </form>
            @endif
        </div>
    @empty
        <p>No tienes suscripciones activas.</p>
    @endforelse

    <form method="post" action="{{ $actionUrl }}" style="margin-top:24px">
        @csrf
        <input type="hidden" name="all" value="1">
        <button type="submit">Darme de baja de todo</button>
    </form>
    <p class="muted" style="margin-top:12px">No volverás a recibir correos de {{ $organization->name }}.</p>
@endsection
