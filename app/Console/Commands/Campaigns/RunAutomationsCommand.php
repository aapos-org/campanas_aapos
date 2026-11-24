<?php

namespace App\Console\Commands\Campaigns;

use App\Jobs\AdvanceAutomationRun;
use App\Services\Automations\AutomationEnroller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunAutomationsCommand extends Command
{
    protected $signature = 'automations:run';

    protected $description = 'Inscribe contactos en las automatizaciones activas y avanza los flujos pendientes';

    public function handle(AutomationEnroller $enroller): int
    {
        $enrolled = $enroller->enrollAll();
        $due = 0;

        DB::table('automation_runs as r')
            ->join('automations as a', 'a.id', '=', 'r.automation_id')
            ->where('a.status', 'active')
            ->where('r.status', 'active')
            ->where(fn ($q) => $q->whereNull('r.next_run_at')->orWhere('r.next_run_at', '<=', now()))
            ->orderBy('r.id')
            ->select('r.id')
            ->chunkById(500, function ($runs) use (&$due) {
                foreach ($runs as $run) {
                    AdvanceAutomationRun::dispatch((int) $run->id);
                    $due++;
                }
            }, 'r.id', 'id');

        if ($enrolled + $due > 0) {
            $this->info("Contactos inscritos: {$enrolled} · Flujos en avance: {$due}");
        }

        return self::SUCCESS;
    }
}
