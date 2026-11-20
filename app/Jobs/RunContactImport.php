<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Services\Imports\ContactImporter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes a whole import sequentially (chunks of 500 rows) on the "imports" queue.
 * Sequential processing avoids races between chunks touching the same contact.
 */
class RunContactImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Large files (100k+ rows) can take several minutes. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $importBatchId)
    {
        $this->onConnection((string) config('queue.long_running_connection'));
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return (string) $this->importBatchId;
    }

    public function handle(ContactImporter $importer, CurrentOrganization $current): void
    {
        $batch = ImportBatch::query()->withoutGlobalScopes()->find($this->importBatchId);

        if ($batch === null || $batch->status !== ImportStatus::Uploaded) {
            return;
        }

        $organization = Organization::query()->findOrFail($batch->organization_id);

        $current->runAs($organization, fn () => $importer->run(ImportBatch::query()->findOrFail($batch->id)));
    }

    public function failed(?Throwable $exception): void
    {
        ImportBatch::query()->withoutGlobalScopes()->whereKey($this->importBatchId)
            ->whereNotIn('status', [ImportStatus::Completed->value, ImportStatus::Cancelled->value])
            ->update([
                'status' => ImportStatus::Failed->value,
                'error' => mb_substr((string) $exception?->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
    }
}
