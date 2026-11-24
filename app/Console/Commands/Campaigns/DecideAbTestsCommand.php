<?php

namespace App\Console\Commands\Campaigns;

use App\Services\Campaigns\AbTester;
use Illuminate\Console\Command;

class DecideAbTestsCommand extends Command
{
    protected $signature = 'campaigns:decide-ab';

    protected $description = 'Elige el ganador de las pruebas A/B cuyo tiempo de espera terminó y lo envía al resto';

    public function handle(AbTester $tester): int
    {
        $count = $tester->decideDue();

        if ($count > 0) {
            $this->info("Pruebas A/B resueltas: {$count}");
        }

        return self::SUCCESS;
    }
}
