<?php

namespace App\Console\Commands\Mailchimp;

use App\Console\Commands\Mailchimp\Concerns\ResolvesOrganization;
use App\Services\Campaigns\EmailHtmlSanitizer;
use App\Services\Mailchimp\MailchimpBackup;
use App\Services\Mailchimp\MailchimpHistoryLoader;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class LoadHistoryCommand extends Command
{
    use ResolvesOrganization;

    protected $signature = 'mailchimp:load-history
        {--snapshot=default : Respaldo a cargar}
        {--organization= : Slug de la organización (por defecto, la primera)}
        {--dry-run : Ensayo en seco: calcula todo y deshace los cambios}
        {--force : No pedir confirmación}';

    protected $description = 'Carga el historial de campañas enviadas de Mailchimp (contenido, resumen y clics por enlace) como campañas históricas de solo lectura';

    public function handle(CurrentOrganization $current, EmailHtmlSanitizer $sanitizer): int
    {
        $backup = new MailchimpBackup((string) $this->option('snapshot'));
        $organization = $this->organization();
        $dryRun = (bool) $this->option('dry-run');

        if ($backup->keys('campaigns') === []) {
            $this->error("El respaldo «{$backup->snapshot}» no tiene campañas. Ejecuta antes mailchimp:extract.");

            return self::FAILURE;
        }

        if (! $backup->isComplete('campaigns')) {
            $this->warn('La extracción de campañas no está completa: se cargará solo lo descargado hasta ahora.');
        }

        if (! $dryRun && ! $this->option('force') && ! confirm("¿Cargar el historial de campañas en «{$organization->name}»?", default: false)) {
            return self::FAILURE;
        }

        $this->info($dryRun ? 'Ensayo en seco (no se guardará nada)…' : 'Cargando historial…');

        $stats = $current->runAs($organization, fn () => (new MailchimpHistoryLoader($backup, $organization, $sanitizer))
            ->onProgress(fn (string $line) => $this->line($line))
            ->run($dryRun));

        $this->table(['Concepto', 'Cantidad'], [
            ['Campañas procesadas', $stats['campaigns']],
            ['Nuevas', $stats['created']],
            ['Actualizadas', $stats['updated']],
            ['Sin contenido HTML en Mailchimp', $stats['without_content']],
            ['Sin audiencia (borrada o no migrada)', $stats['without_audience']],
            ['Enlaces con clics', $stats['links']],
        ]);

        if ($stats['without_audience'] > 0) {
            $this->warn('Hay campañas sin audiencia: su audiencia se borró en Mailchimp o aún no se migró (en ese caso, ejecuta mailchimp:load y vuelve a cargar el historial).');
        }

        $this->line(sprintf(
            'Plantillas en Mailchimp: %d. No se importan: la API solo entrega sus secciones editables, no el HTML completo. '
            .'Para reutilizar un diseño, abre una campaña histórica y usa «Duplicar como borrador».',
            $stats['templates_total'],
        ));

        if (! $dryRun) {
            Audit::record('mailchimp.history_loaded', 'Historial de campañas de Mailchimp cargado', null, $stats + ['snapshot' => $backup->snapshot], organizationId: $organization->id);
        }

        $this->info($dryRun ? 'Ensayo terminado: no se guardó nada.' : 'Historial cargado.');

        return self::SUCCESS;
    }
}
