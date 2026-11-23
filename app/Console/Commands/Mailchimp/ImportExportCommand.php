<?php

namespace App\Console\Commands\Mailchimp;

use App\Services\Mailchimp\MailchimpBackup;
use App\Services\Mailchimp\MailchimpExportConverter;
use Illuminate\Console\Command;

class ImportExportCommand extends Command
{
    protected $signature = 'mailchimp:import-export
        {path : Archivo .zip o carpeta con los CSV exportados desde el panel de Mailchimp}
        {--audience= : Nombre de la audiencia}
        {--snapshot=csv : Respaldo donde guardarlo}';

    protected $description = 'Alternativa sin API: convierte la exportación CSV de una audiencia de Mailchimp al formato del respaldo';

    public function handle(): int
    {
        $name = (string) ($this->option('audience') ?: $this->ask('Nombre de la audiencia'));
        $backup = new MailchimpBackup((string) $this->option('snapshot'));

        $result = (new MailchimpExportConverter($backup))->convert((string) $this->argument('path'), $name);

        foreach ($result['members'] as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        $this->info("Listo. Ahora ejecuta: php artisan mailchimp:load --snapshot={$backup->snapshot} --dry-run");

        return self::SUCCESS;
    }
}
