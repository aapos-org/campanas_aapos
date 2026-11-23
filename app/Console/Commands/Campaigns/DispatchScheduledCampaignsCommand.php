<?php

namespace App\Console\Commands\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Organization;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\PreSendChecklist;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Throwable;

class DispatchScheduledCampaignsCommand extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';

    protected $description = 'Inicia las campañas programadas cuya hora ya llegó';

    public function handle(CampaignSender $sender, PreSendChecklist $checklist, CurrentOrganization $current): int
    {
        $due = Campaign::query()->withoutGlobalScopes()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->whereNull('deleted_at')
            ->get();

        foreach ($due as $campaign) {
            $organization = Organization::query()->findOrFail($campaign->organization_id);

            $current->runAs($organization, function () use ($campaign, $organization, $sender, $checklist) {
                $checks = $checklist->run($campaign, $organization);

                if (PreSendChecklist::blocks($checks)) {
                    $campaign->forceFill([
                        'status' => CampaignStatus::Failed,
                        'status_reason' => 'No se envió: '.collect($checks)->where('status', 'error')->pluck('message')->implode(' '),
                    ])->save();
                    $this->warn("Campaña {$campaign->id}: no pasó la verificación.");

                    return;
                }

                try {
                    $sender->start($campaign);
                    $this->info("Campaña {$campaign->id} iniciada.");
                } catch (Throwable $e) {
                    $this->error("Campaña {$campaign->id}: {$e->getMessage()}");
                }
            });
        }

        return self::SUCCESS;
    }
}
