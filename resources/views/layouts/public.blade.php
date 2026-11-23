@php
    $primary = isset($form) ? $form->color('primary_color') : '#0f766e';
    $background = isset($form) ? $form->color('background_color') : '#ffffff';
    $text = isset($form) ? $form->color('text_color') : '#111827';
    $embed = $embed ?? false;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style nonce="{{ Vite::cspNonce() }}">
        :root { --primary: {{ $primary }}; --bg: {{ $background }}; --text: {{ $text }}; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--text); background: {{ $embed ? 'transparent' : '#f4f4f5' }}; line-height: 1.5; }
        main { max-width: 560px; margin: {{ $embed ? '0' : '40px auto' }}; padding: {{ $embed ? '0' : '0 16px' }}; }
        .card { background: var(--bg); border-radius: 12px; padding: 28px 24px; {{ $embed ? '' : 'box-shadow: 0 1px 3px rgba(0,0,0,.08);' }} }
        h1 { font-size: 1.5rem; margin: 0 0 8px; }
        p { margin: 0 0 16px; }
        label { display: block; font-weight: 600; font-size: .95rem; margin-bottom: 6px; }
        .field { margin-bottom: 16px; }
        input[type=text], input[type=email], input[type=tel], input[type=number], input[type=date], select {
            width: 100%; padding: 10px 12px; font-size: 1rem; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; color: #111827;
        }
        input:focus-visible, select:focus-visible, button:focus-visible { outline: 3px solid var(--primary); outline-offset: 2px; }
        button { width: 100%; padding: 12px 16px; font-size: 1rem; font-weight: 600; color: #fff; background: var(--primary); border: 0; border-radius: 8px; cursor: pointer; }
        .error { color: #b91c1c; font-size: .875rem; margin-top: 4px; }
        .alert { background: #fef2f2; color: #991b1b; border-radius: 8px; padding: 12px; margin-bottom: 16px; }
        .muted { color: #6b7280; font-size: .85rem; }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        .row { display: flex; gap: 12px; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e5e7eb; }
        .row button { width: auto; padding: 8px 14px; font-size: .9rem; }
        .secondary { background: #e5e7eb; color: #111827; }
        .ok { background: #ecfdf5; color: #065f46; border-radius: 8px; padding: 12px; margin-bottom: 16px; }
    </style>
    @stack('head')
</head>
<body>
    <main>
        <div class="card">
            @yield('content')
        </div>
    </main>
    @if ($embed)
        {{-- Tells the parent page (JS embed) the height of the form. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                function send() { parent.postMessage({ type: 'campanas-form-height', height: document.documentElement.scrollHeight }, '*'); }
                window.addEventListener('load', send);
                new ResizeObserver(send).observe(document.body);
            })();
        </script>
    @endif
</body>
</html>
