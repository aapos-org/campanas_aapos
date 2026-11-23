<?php

namespace App\Services\Campaigns;

use App\Enums\ConnectionStatus;
use App\Models\Campaign;
use App\Models\Organization;
use App\Services\Sending\DailySendQuota;
use App\Support\Http\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Checks run before sending. Any "error" blocks the send; "warning" only informs.
 */
class PreSendChecklist
{
    public function __construct(
        private readonly RecipientFreezer $freezer,
        private readonly DailySendQuota $quota,
        private readonly LinkRegistry $links,
    ) {}

    /**
     * @return list<array{key: string, status: string, message: string}> status: ok | warning | error
     */
    public function run(Campaign $campaign, Organization $organization, bool $checkLinks = false): array
    {
        $checks = [];
        $add = function (string $key, bool $ok, string $okMessage, string $failMessage, string $failStatus = 'error') use (&$checks) {
            $checks[] = ['key' => $key, 'status' => $ok ? 'ok' : $failStatus, 'message' => $ok ? $okMessage : $failMessage];
        };

        $add('subject', filled($campaign->subject), 'Tiene asunto.', 'Falta el asunto.');
        $add('preheader', filled($campaign->preheader), 'Tiene texto de vista previa.', 'Sin texto de vista previa (recomendado).', 'warning');
        $add('content', filled(strip_tags((string) $campaign->html)), 'Tiene contenido.', 'El contenido está vacío.');
        $add('audience', $campaign->audience_id !== null, 'Audiencia elegida.', 'Elige una audiencia.');
        $add('address', filled($organization->physical_address), 'La dirección física de la organización aparecerá en el pie.', 'Falta la dirección física de la organización (obligatoria en el pie).');
        $add('unsubscribe', true, 'Se incluye el enlace de baja y la baja en un clic (List-Unsubscribe).', '');

        $connection = $campaign->mailConnection;
        $add('connection', $connection !== null && $connection->status === ConnectionStatus::Active,
            'La conexión «'.($connection->name ?? '').'» está activa.',
            $connection === null ? 'Elige una conexión de envío.' : "La conexión «{$connection->name}» no está verificada o tiene errores.");

        $recipients = $this->freezer->estimate($campaign);
        $add('recipients', $recipients > 0, "Se enviará a {$recipients} contactos suscritos (sin suprimidos).", 'No hay contactos suscritos que cumplan los filtros.');

        if ($connection !== null && $connection->daily_limit !== null) {
            $remaining = (int) $this->quota->remainingToday($connection);
            $add('quota', $remaining >= $recipients,
                "La conexión tiene cupo hoy ({$remaining} restantes).",
                "La conexión solo puede enviar {$remaining} correos más hoy y hay {$recipients} destinatarios: el envío se pausará al llegar al límite.",
                $connection->driver->isGmail() ? 'error' : 'warning');
        }

        $add('test', $campaign->last_test_sent_at !== null, 'Se envió un correo de prueba.', 'Aún no envías un correo de prueba (recomendado).', 'warning');

        if ($checkLinks) {
            $broken = $this->brokenLinks($campaign);
            $add('links', $broken === [], 'Todos los enlaces responden.', 'Enlaces que no responden: '.implode(', ', array_slice($broken, 0, 5)), 'warning');
        }

        return $checks;
    }

    /**
     * @param  list<array{status: string}>  $checks
     */
    public static function blocks(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * HEAD (then GET) to each public link, in parallel, without following redirects to
     * internal addresses (SSRF). Links with personalization variables are skipped.
     *
     * @return list<string>
     */
    private function brokenLinks(Campaign $campaign): array
    {
        $urls = array_values(array_filter(
            array_slice($this->links->extract((string) $campaign->html), 0, 40),
            fn (string $url) => ! str_contains($url, '{{'),
        ));

        $broken = [];
        $checkable = [];

        foreach ($urls as $url) {
            SafeUrl::isPublic($url) ? $checkable[] = $url : $broken[] = $url;
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $pool->as($url)->withOptions(['allow_redirects' => false])->timeout(8)->head($url),
                $checkable,
            ));
        } catch (ConnectionException) {
            return $broken;
        }

        foreach ($checkable as $url) {
            $response = $responses[$url] ?? null;
            $status = $response instanceof Response ? $response->status() : 0;

            // 3xx is fine (not followed on purpose); 405 = HEAD not allowed by the site.
            if ($status === 0 || ($status >= 400 && $status !== 405)) {
                $broken[] = $url;
            }
        }

        return $broken;
    }
}
