<?php

namespace App\Console\Commands\Mailchimp;

use App\Services\Mailchimp\MailchimpBackup;
use App\Services\Mailchimp\MailchimpClient;
use App\Services\Mailchimp\MailchimpExtractor;
use App\Support\Audit;
use Illuminate\Console\Command;
use Throwable;

class ExtractCommand extends Command
{
    protected $signature = 'mailchimp:extract
        {--snapshot=default : Nombre del respaldo (carpeta en storage/app/private/mailchimp)}
        {--without-campaigns : No descargar campañas ni reportes}
        {--without-activity : No descargar destinatarios ni actividad por correo (más rápido)}
        {--campaigns-since= : Solo campañas enviadas desde esta fecha (AAAA-MM-DD)}';

    protected $description = 'Descarga la cuenta de Mailchimp (solo lectura) a un respaldo cifrado y reanudable';

    public function handle(): int
    {
        if (blank(config('services.mailchimp.api_key'))) {
            $this->error('Falta MAILCHIMP_API_KEY en el archivo .env.');

            return self::FAILURE;
        }

        $backup = new MailchimpBackup((string) $this->option('snapshot'));
        $since = $this->option('campaigns-since');

        try {
            (new MailchimpExtractor(MailchimpClient::fromConfig(), $backup))
                ->onProgress(fn (string $line) => $this->line($line))
                ->run([
                    'campaigns' => ! $this->option('without-campaigns'),
                    'activity' => ! $this->option('without-activity'),
                    'campaigns_since' => is_string($since) && $since !== '' ? $since.'T00:00:00+00:00' : null,
                ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->warn('Vuelve a ejecutar el mismo comando: continuará desde lo ya descargado.');

            return self::FAILURE;
        }

        Audit::record('mailchimp.extracted', 'Extracción de Mailchimp completada', null, ['snapshot' => $backup->snapshot]);

        return self::SUCCESS;
    }
}
