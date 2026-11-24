<?php

namespace App\Services\Automations;

use App\Enums\AutomationStepType;
use App\Enums\ContactStatus;
use App\Jobs\SendCampaignBatch;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\CampaignRecipient;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Tag;
use App\Services\Contacts\SuppressionService;
use App\Services\Segments\SegmentQuery;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Advances one run through its steps until it has to wait, finishes or exits.
 * Each step is executed inside a row lock, so two workers never process the same run.
 */
class AutomationRunner
{
    /** Safety net against misconfigured flows. */
    private const int MAX_STEPS_PER_PASS = 50;

    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly SuppressionService $suppressions,
        private readonly SegmentQuery $segments,
    ) {}

    public function advance(int $runId): void
    {
        $organizationId = AutomationRun::query()->withoutGlobalScopes()->whereKey($runId)->value('organization_id');
        $organization = $organizationId !== null ? Organization::query()->find((int) $organizationId) : null;

        if ($organization === null) {
            return;
        }

        $this->current->runAs($organization, function () use ($runId) {
            $emails = [];

            DB::transaction(function () use ($runId, &$emails) {
                /** @var AutomationRun|null $run */
                $run = AutomationRun::query()->with(['automation.steps.campaign', 'contact'])->lockForUpdate()->find($runId);

                if ($run === null || $run->status !== 'active' || ($run->next_run_at !== null && $run->next_run_at->isFuture())) {
                    return;
                }

                $automation = $run->automation;

                if ($automation === null || ! $automation->isActive()) {
                    return; // paused: the run waits where it is
                }

                for ($i = 0; $i < self::MAX_STEPS_PER_PASS; $i++) {
                    $step = $automation->steps->firstWhere('id', $run->next_step_id);

                    if ($step === null) {
                        $this->finish($run, 'completed', null, 'Flujo completado.');

                        return;
                    }

                    if (($reason = $this->exitReason($run, $automation)) !== null) {
                        $this->finish($run, 'exited', $reason, "Salió del flujo: {$reason}.");

                        return;
                    }

                    $outcome = $this->execute($run, $automation, $step, $emails);
                    $next = $this->nextStep($automation, $step, $outcome === 'skip' ? 2 : 1);

                    if ($outcome === 'exit') {
                        $this->finish($run, 'exited', 'No cumplió la condición', 'Salió del flujo: no cumplió la condición.');

                        return;
                    }

                    $run->next_step_id = $next?->id;

                    if ($outcome === 'wait') {
                        $run->save();

                        return;
                    }

                    $run->next_run_at = null;
                    $run->save();
                }

                $run->log('Se alcanzó el máximo de pasos seguidos; continuará en la próxima ejecución.');
            });

            // Emails are queued after the transaction commits.
            foreach ($emails as [$campaignId, $recipientId]) {
                SendCampaignBatch::dispatch($campaignId, [$recipientId])
                    ->onConnection((string) config('queue.long_running_connection'))
                    ->onQueue('emails');
            }
        });
    }

    /**
     * @param  list<array{0: int, 1: int}>  $emails
     * @return 'next'|'wait'|'skip'|'exit'
     */
    private function execute(AutomationRun $run, Automation $automation, AutomationStep $step, array &$emails): string
    {
        $config = $step->config;

        switch ($step->type) {
            case AutomationStepType::SendEmail:
                $campaign = $step->campaign;

                if ($campaign === null) {
                    $run->log('Paso de correo sin contenido: se omitió.', $step->id);

                    return 'next';
                }

                $recipient = CampaignRecipient::query()->firstOrCreate(
                    ['campaign_id' => $campaign->id, 'automation_run_id' => $run->id],
                    [
                        'organization_id' => $run->organization_id,
                        'contact_id' => $run->contact_id,
                        'subscription_id' => $run->subscription_id,
                        'email' => (string) $run->contact?->email,
                        'status' => 'pending',
                        'token' => Str::lower(Str::random(32)),
                    ],
                );

                if ($recipient->wasRecentlyCreated) {
                    $emails[] = [$campaign->id, $recipient->id];
                    $run->log('Correo en cola: «'.$campaign->subject.'».', $step->id);
                }

                return 'next';

            case AutomationStepType::Wait:
                $amount = max(1, (int) ($config['amount'] ?? 1));
                $unit = in_array($config['unit'] ?? null, ['minutes', 'hours', 'days'], true) ? $config['unit'] : 'days';
                $run->next_run_at = now()->add($unit, $amount);
                $run->log('Esperando '.AutomationStepType::duration($amount, $unit).'.', $step->id);

                return 'wait';

            case AutomationStepType::AddTag:
            case AutomationStepType::RemoveTag:
                $tag = Tag::query()->find((int) ($config['tag_id'] ?? 0));

                if ($tag === null) {
                    $run->log('La etiqueta ya no existe: se omitió el paso.', $step->id);

                    return 'next';
                }

                if ($step->type === AutomationStepType::AddTag) {
                    $tag->contacts()->syncWithoutDetaching([$run->contact_id => ['created_at' => now()]]);
                    $run->log("Etiqueta agregada: «{$tag->name}».", $step->id);
                } else {
                    $tag->contacts()->detach($run->contact_id);
                    $run->log("Etiqueta quitada: «{$tag->name}».", $step->id);
                }

                return 'next';

            case AutomationStepType::Condition:
                $matches = $run->subscription_id !== null && $this->segments->matches(
                    $run->subscription_id,
                    $automation->audience_id,
                    (string) ($config['match'] ?? 'all'),
                    $step->conditionList(),
                );

                if ($matches) {
                    $run->log('Cumple la condición: continúa.', $step->id);

                    return 'next';
                }

                if (($config['on_false'] ?? 'exit') === 'skip') {
                    $run->log('No cumple la condición: se salta el paso siguiente.', $step->id);

                    return 'skip';
                }

                return 'exit';
        }
    }

    private function nextStep(Automation $automation, AutomationStep $current, int $offset): ?AutomationStep
    {
        return $automation->steps->where('position', '>', $current->position)->sortBy('position')->values()->get($offset - 1);
    }

    private function exitReason(AutomationRun $run, Automation $automation): ?string
    {
        $contact = $run->contact;

        if ($contact === null) {
            return 'el contacto fue eliminado';
        }

        $status = Subscription::query()->where('audience_id', $automation->audience_id)->where('contact_id', $contact->id)->toBase()->value('status');

        if ($status !== ContactStatus::Subscribed->value) {
            return 'ya no está suscrito';
        }

        if ($this->suppressions->isSuppressed($contact->email, $automation->audience_id)) {
            return 'está en la lista de supresión';
        }

        return null;
    }

    private function finish(AutomationRun $run, string $status, ?string $reason, string $message): void
    {
        $run->forceFill(['status' => $status, 'exit_reason' => $reason, 'finished_at' => now(), 'next_run_at' => null])->save();
        $run->log($message);
    }
}
