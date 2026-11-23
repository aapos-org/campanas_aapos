<?php

namespace App\Console\Commands\Campaigns;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureEventPartitionsCommand extends Command
{
    protected $signature = 'events:partitions {--months=3 : Meses hacia adelante} {--from= : Crear también desde este mes (AAAA-MM), p. ej. para historial migrado}';

    protected $description = 'Crea las particiones mensuales de email_events que falten';

    public function handle(): int
    {
        $from = ($this->option('from') ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('from')) : null)
            ?? CarbonImmutable::now()->startOfMonth();
        $last = CarbonImmutable::now()->startOfMonth()->addMonths(max(1, (int) $this->option('months')));
        $created = 0;

        for ($month = $from->startOfMonth(); $month <= $last; $month = $month->addMonth()) {
            $name = 'email_events_'.$month->format('Y_m');
            $exists = DB::selectOne('SELECT to_regclass(?) AS t', ['public.'.$name])?->t;

            if ($exists !== null) {
                continue;
            }

            // Rows of that month sitting in the default partition must be moved first.
            DB::transaction(function () use ($name, $month) {
                $start = $month->toDateString();
                $end = $month->addMonth()->toDateString();
                DB::statement('CREATE TEMP TABLE moved_events ON COMMIT DROP AS SELECT * FROM email_events_default WHERE occurred_at >= ? AND occurred_at < ?', [$start, $end]);
                DB::statement('DELETE FROM email_events_default WHERE occurred_at >= ? AND occurred_at < ?', [$start, $end]);
                DB::statement("CREATE TABLE {$name} PARTITION OF email_events FOR VALUES FROM ('{$start}') TO ('{$end}')");
                DB::statement('INSERT INTO email_events SELECT * FROM moved_events');
            });

            $created++;
        }

        $this->info("Particiones creadas: {$created}");

        return self::SUCCESS;
    }
}
