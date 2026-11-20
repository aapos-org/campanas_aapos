<?php

it('sends a nonce-based CSP without unsafe script sources', function () {
    $response = $this->get(route('login'))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->not->toContain('unsafe-eval');

    preg_match("/script-src [^;]*'unsafe-inline'/", $csp, $matches);
    expect($matches)->toBeEmpty();
});

it('uses the same nonce in the CSP header and in the page scripts', function () {
    $response = $this->get(route('login'))->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);

    expect($m[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$m[1].'"');
});

it('sends hardening headers', function () {
    $this->get(route('login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});
