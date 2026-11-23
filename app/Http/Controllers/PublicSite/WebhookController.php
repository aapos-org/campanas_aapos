<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookCall;
use App\Models\WebhookCall;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ElasticEmail notifications (GET or POST JSON). Authenticated with a secret token in the
 * URL (ElasticEmail also offers HMAC signing; the header is stored to verify it once its
 * format is confirmed with real notifications). The raw request is stored and processed
 * on the queue, so the endpoint answers immediately.
 */
class WebhookController extends Controller
{
    public function elasticEmail(Request $request, string $token): Response
    {
        $expected = (string) config('services.elasticemail.webhook_token');

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $payload = $request->isJson() ? (array) $request->json()->all() : $request->query();

        if ($payload === []) {
            $payload = $request->post();
        }

        $call = WebhookCall::create([
            'provider' => 'elasticemail',
            'method' => $request->method(),
            'payload' => $payload,
            'headers' => collect($request->headers->all())
                ->filter(fn ($v, $k) => str_starts_with($k, 'x-') || in_array($k, ['content-type', 'user-agent'], true))
                ->map(fn (array $v) => implode(', ', array_filter($v)))
                ->all(),
            'ip' => $request->ip(),
        ]);

        ProcessWebhookCall::dispatch($call->id);

        return response('OK', 200);
    }
}
