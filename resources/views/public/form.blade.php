@extends('layouts.public')

@section('title', $form->text('title'))

@if ($turnstileSiteKey)
    @push('head')
        <script nonce="{{ Vite::cspNonce() }}" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
    <h1>{{ $form->text('title') }}</h1>
    @if ($form->text('description'))
        <p>{{ $form->text('description') }}</p>
    @endif

    @if ($formErrors->has('form'))
        <div class="alert" role="alert">{{ $formErrors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('forms.public.submit', ['slug' => $form->slug]) }}" novalidate>
        <input type="hidden" name="_ts" value="{{ $timestamp }}">
        @if ($embed)<input type="hidden" name="embed" value="1">@endif

        <div class="hp" aria-hidden="true">
            <label for="website">No completes este campo</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        @foreach ($form->fields as $field)
            @php
                $key = $field['key'];
                $type = $fieldTypes[$key] ?? null;
                $inputType = match (true) {
                    $key === 'email' => 'email',
                    $key === 'phone' || $type === 'phone' => 'tel',
                    $type === 'number' => 'number',
                    $type === 'date' => 'date',
                    default => 'text',
                };
                $value = $old[$key] ?? '';
            @endphp
            <div class="field">
                <label for="f-{{ $key }}">{{ $field['label'] }}@if ($field['required'] || $key === 'email') *@endif</label>
                @if ($type === 'select')
                    <select id="f-{{ $key }}" name="{{ $key }}" @if ($field['required']) required @endif>
                        <option value="">—</option>
                        @foreach ($fieldOptions[$key] ?? [] as $option)
                            <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                @else
                    <input
                        type="{{ $inputType }}"
                        id="f-{{ $key }}"
                        name="{{ $key }}"
                        value="{{ $value }}"
                        @if ($field['required'] || $key === 'email') required @endif
                        @if ($key === 'email') autocomplete="email" @elseif ($key === 'first_name') autocomplete="given-name" @elseif ($key === 'last_name') autocomplete="family-name" @elseif ($key === 'phone') autocomplete="tel" @endif
                        @if ($type === 'birthday') placeholder="dd/mm" @endif
                        @if ($formErrors->has($key)) aria-invalid="true" aria-describedby="e-{{ $key }}" @endif
                    >
                @endif
                @if ($formErrors->has($key))
                    <div class="error" id="e-{{ $key }}">{{ $formErrors->first($key) }}</div>
                @endif
            </div>
        @endforeach

        @if ($turnstileSiteKey)
            <div class="field cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-language="es"></div>
        @endif

        <button type="submit">{{ $form->text('button') }}</button>

        @if ($form->audience->permission_reminder)
            <p class="muted" style="margin-top:12px">{{ $form->audience->permission_reminder }}</p>
        @endif
        <p class="muted">Puedes darte de baja en cualquier momento desde el enlace al pie de cada correo.</p>
    </form>
@endsection
