<?php

namespace App\Console\Commands\Campaigns;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WebhookTokenCommand extends Command
{
    protected $signature = 'app:webhook-token';

    protected $description = 'Genera un token secreto para la URL del webhook de ElasticEmail';

    public function handle(): int
    {
        $token = Str::lower(Str::random(48));

        $this->line('Agrega esta línea al archivo .env del servidor:');
        $this->newLine();
        $this->line("ELASTICEMAIL_WEBHOOK_TOKEN={$token}");
        $this->newLine();
        $this->line('Y configura en ElasticEmail (Settings → Webhooks) esta URL, con envío POST JSON:');
        $this->line(rtrim((string) config('app.url'), '/')."/webhooks/elasticemail/{$token}");

        return self::SUCCESS;
    }
}
