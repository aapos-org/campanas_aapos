<?php

namespace App\Jobs;

use App\Models\WebhookCall;
use App\Services\Webhooks\ElasticEmailWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWebhookCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $webhookCallId) {}

    public function handle(ElasticEmailWebhookProcessor $processor): void
    {
        $call = WebhookCall::query()->find($this->webhookCallId);

        if ($call === null || $call->status === 'processed') {
            return;
        }

        $processor->process($call);
    }

    public function failed(?Throwable $exception): void
    {
        WebhookCall::query()->whereKey($this->webhookCallId)->update([
            'status' => 'failed',
            'result' => mb_substr((string) $exception?->getMessage(), 0, 2000),
        ]);
    }
}
