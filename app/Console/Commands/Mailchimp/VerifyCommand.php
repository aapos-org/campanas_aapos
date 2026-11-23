<?php

namespace App\Console\Commands\Mailchimp;

use App\Console\Commands\Mailchimp\Concerns\ResolvesOrganization;
use App\Services\Mailchimp\MailchimpBackup;
use App\Services\Mailchimp\MigrationReporter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    use ResolvesOrganization;

    protected $signature = 'mailchimp:verify
        {--snapshot=default}
        {--organization=}
        {--sample=50 : Tamaño de la muestra aleatoria}
        {--output= : Ruta del informe (por defecto docs/migracion_informe.md)}';

    protected $description = 'Compara la aplicación contra el respaldo de Mailchimp y genera el informe de verificación';

    public function handle(CurrentOrganization $current): int
    {
        $backup = new MailchimpBackup((string) $this->option('snapshot'));
        $organization = $this->organization();
        $output = (string) ($this->option('output') ?: base_path('docs/migracion_informe.md'));

        $result = $current->runAs($organization, fn () => (new MigrationReporter($backup, $organization))
            ->verify($output, max(1, (int) $this->option('sample'))));

        $this->info('Informe: '.$result['report']);

        if ($result['differences'] > 0) {
            $this->warn("Se encontraron {$result['differences']} diferencias. No des de baja Mailchimp hasta revisarlas.");

            return self::FAILURE;
        }

        $this->info('Sin diferencias.');

        return self::SUCCESS;
    }
}
