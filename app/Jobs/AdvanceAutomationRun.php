<?php

namespace App\Jobs;

use App\Services\Automations\AutomationRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdvanceAutomationRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(AutomationRunner $runner): void
    {
        $runner->advance($this->runId);
    }
}
