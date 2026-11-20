<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict Content-Security-Policy (nonce based, no 'unsafe-inline' / 'unsafe-eval' for scripts)
 * plus the usual hardening headers.
 *
 * The Horizon dashboard ships its own inline scripts, so it receives a relaxed policy;
 * it is only reachable by administrators.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Public forms can be embedded in iframes on other sites; everything else cannot.
        if (! $this->isEmbeddable($request)) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($request, $nonce));
        }

        return $response;
    }

    private function policy(Request $request, string $nonce): string
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');

        if ($request->is($horizonPath, $horizonPath.'/*')) {
            return "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
        }

        $hot = $this->viteDevServer();
        $embeddable = $this->isEmbeddable($request);
        $turnstile = $request->is('f/*') ? 'https://challenges.cloudflare.com' : null;
        $hotWs = $hot !== null ? preg_replace('#^http#', 'ws', $hot) : null;

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", $hot, $turnstile],
            // Vue/reka-ui set inline style attributes for positioning (popovers, sidebar width).
            'style-src' => ["'self'", "'nonce-{$nonce}'", $hot],
            'style-src-attr' => ["'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:', $hot],
            'connect-src' => ["'self'", $hot, $hotWs, $turnstile],
            // Own pages only (form preview in the editor) plus Turnstile on public forms.
            'frame-src' => ["'self'", $turnstile],
            'frame-ancestors' => [$embeddable ? '*' : "'none'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_filter($sources)))
            ->implode('; ');
    }

    private function isEmbeddable(Request $request): bool
    {
        return $request->is('f/*') && $request->isMethod('GET');
    }

    private function viteDevServer(): ?string
    {
        $hotFile = Vite::hotFile();

        if (! app()->isLocal() || ! is_file($hotFile)) {
            return null;
        }

        $url = rtrim((string) file_get_contents($hotFile));

        return $url !== '' ? $url : null;
    }
}
