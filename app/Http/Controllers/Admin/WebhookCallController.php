<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookCall;
use Inertia\Inertia;
use Inertia\Response;

class WebhookCallController extends Controller
{
    public function index(): Response
    {
        $token = (string) config('services.elasticemail.webhook_token');

        return Inertia::render('admin/webhooks/Index', [
            'calls' => WebhookCall::query()->latest('id')->paginate(50)->through(fn (WebhookCall $c) => [
                'id' => $c->id,
                'provider' => $c->provider,
                'method' => $c->method,
                'status' => $c->status,
                'result' => $c->result,
                'payload' => $c->payload,
                'headers' => $c->headers,
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'webhookUrl' => $token !== '' ? route('webhooks.elasticemail', ['token' => $token]) : null,
        ]);
    }
}
