<?php

namespace App\Console\Commands\Mailchimp;

use App\Console\Commands\Mailchimp\Concerns\ResolvesOrganization;
use App\Services\Contacts\ContactWriter;
use App\Services\Contacts\FieldNormalizer;
use App\Services\Mailchimp\MailchimpBackup;
use App\Services\Mailchimp\MailchimpLoader;
use App\Services\Mailchimp\MigrationReporter;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class LoadCommand extends Command
{
    use ResolvesOrganization;

    protected $signature = 'mailchimp:load
        {--snapshot=default : Respaldo a cargar}
        {--organization= : Slug de la organización (por defecto, la primera)}
        {--dry-run : Ensayo en seco: calcula todo y deshace los cambios}
        {--reports-dir= : Carpeta para los informes de automatizaciones/formularios/segmentos (por defecto docs/)}
        {--force : No pedir confirmación}';

    protected $description = 'Carga el respaldo de Mailchimp: staging → normalización → audiencias, contactos, supresiones y etiquetas';

    public function handle(CurrentOrganization $current, FieldNormalizer $normalizer, ContactWriter $writer): int
    {
        $backup = new MailchimpBackup((string) $this->option('snapshot'));
        $organization = $this->organization();
        $dryRun = (bool) $this->option('dry-run');

        if ($backup->keys('lists') === []) {
            $this->error("El respaldo «{$backup->snapshot}» está vacío. Ejecuta antes mailchimp:extract o mailchimp:import-export.");

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->option('force')) {
            $reports = $backup->disk()->files($backup->root().'/reports');
            $hasDryRun = array_filter($reports, fn (string $f) => str_contains($f, '/ensayo-')) !== [];

            if (! $hasDryRun) {
                $this->error('Primero ejecuta un ensayo en seco: php artisan mailchimp:load --dry-run');

                return self::FAILURE;
            }

            if (! confirm("¿Cargar el respaldo «{$backup->snapshot}» en «{$organization->name}»?", default: false)) {
                return self::FAILURE;
            }
        }

        $this->info($dryRun ? 'Ensayo en seco (no se guardará nada)…' : 'Cargando…');

        $stats = $current->runAs($organization, fn () => (new MailchimpLoader($backup, $organization, $normalizer, $writer))
            ->onProgress(fn (string $line) => $this->line($line))
            ->run($dryRun));

        $reporter = new MigrationReporter($backup, $organization);
        $reportPath = $reporter->loadReport($stats);

        if (! $dryRun) {
            $docs = $current->runAs($organization, fn () => $reporter->pendingReports((string) ($this->option('reports-dir') ?: base_path('docs'))));
            $this->line('Informes para reconstrucción manual: '.implode(', ', array_map('basename', $docs)));
            Audit::record('mailchimp.loaded', 'Migración de Mailchimp cargada', null, ['snapshot' => $backup->snapshot], organizationId: $organization->id);
        }

        $rows = [];
        foreach ((array) $stats['audiences'] as $a) {
            $rejected = count(array_filter((array) $stats['rejected'], fn ($r) => ($r['audience'] ?? null) === $a['name']));
            $rows[] = [$a['name'], $a['contacts_created'], $a['contacts_updated'], $a['reactivation_prevented'], $rejected];
        }
        $this->table(['Audiencia', 'Nuevos', 'Actualizados', 'No reactivados', 'Rechazados'], $rows);

        $this->info('Informe: storage/app/private/'.$reportPath);

        if ($dryRun) {
            $this->warn('Ensayo en seco: no se guardó ningún cambio. Revisa el informe y luego ejecuta la carga sin --dry-run.');
        } else {
            $this->info('Siguiente paso: php artisan mailchimp:verify');
        }

        return self::SUCCESS;
    }
}
