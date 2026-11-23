<?php

namespace App\Http\Controllers\Campaigns;

use Illuminate\Http\Response;

/**
 * Email HTML served for preview iframes: its own CSP forbids every script and only our
 * panel may frame it. The iframe itself is also sandboxed.
 */
class PreviewResponse
{
    public static function make(string $html): Response
    {
        return response($html !== '' ? $html : '<p style="font-family:sans-serif;color:#6b7280;padding:24px">Sin contenido todavía.</p>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline' https:; font-src https: data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }
}
