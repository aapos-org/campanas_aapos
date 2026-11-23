<?php

namespace App\Services\Mailchimp;

use App\Enums\ContactStatus;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Markdown reports of the migration. Emails are always masked (a***@dominio.org):
 * reports may be shared or committed, the backup may not.
 */
class MigrationReporter
{
    public function __construct(
        private readonly MailchimpBackup $backup,
        private readonly Organization $organization,
    ) {}

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }

    /**
     * Load (or dry-run) report, stored inside the private backup folder.
     *
     * @param  array<string, mixed>  $stats
     */
    public function loadReport(array $stats): string
    {
        $lines = [
            '# '.($stats['dry_run'] ? 'Ensayo en seco' : 'Carga').' de la migración de Mailchimp',
            '',
            '- Snapshot: `'.$stats['snapshot'].'`',
            '- Inicio: '.$stats['started_at'].' · Fin: '.($stats['finished_at'] ?? '—'),
            $stats['dry_run'] ? '- **Ensayo en seco: no se guardó ningún cambio.** Las cifras muestran lo que haría la carga real.' : '- Carga real aplicada.',
            '',
            '## Audiencias',
            '',
            '| Audiencia | En Mailchimp (suscritos / bajas / limpiados) | Contactos nuevos | Actualizados | Estado local resultante | No reactivados | Suscritos sin fecha de opt-in | Valores descartados |',
            '|---|---|---|---|---|---|---|---|',
        ];

        foreach ($stats['audiences'] as $a) {
            $mc = $a['mailchimp_stats'];
            $parts = [];
            foreach ((array) $a['by_local_status'] as $s => $n) {
                $parts[] = (ContactStatus::tryFrom((string) $s)?->label() ?? $s).': '.$n;
            }
            $local = implode(', ', $parts);
            $lines[] = sprintf(
                '| %s | %s / %s / %s | %d | %d | %s | %d | %d | %d |',
                str_replace('|', '\|', (string) $a['name']),
                $mc['member_count'] ?? '?', $mc['unsubscribe_count'] ?? '?', $mc['cleaned_count'] ?? '?',
                $a['contacts_created'], $a['contacts_updated'], $local ?: '—',
                $a['reactivation_prevented'], $a['subscribed_without_optin_date'], $a['field_warnings'],
            );
        }

        $lines = [...$lines, '',
            '## Otros datos',
            '',
            '- Campos personalizados creados: '.$stats['fields_created'].' · reutilizados: '.$stats['fields_reused'],
            '- Etiquetas en la organización: '.$stats['tags'],
            '- Suscripciones en otras audiencias suprimidas por rebote o queja global: '.$stats['cross_audience_suppressed'],
            '- Pendientes en staging para fases posteriores: '.(implode(', ', array_map(fn ($r, $n) => "{$r}: {$n}", array_keys((array) $stats['pending_staged']), (array) $stats['pending_staged'])) ?: 'ninguno'),
            '',
            '## Registros rechazados ('.count($stats['rejected']).')',
            '',
        ];

        foreach (array_slice($stats['rejected'], 0, 500) as $r) {
            $lines[] = '- '.self::mask((string) $r['email']).' · '.$r['audience'].' · '.$r['reason'];
        }

        $path = $this->backup->root().'/reports/'.($stats['dry_run'] ? 'ensayo' : 'carga').'-'.now()->format('Ymd-His').'.md';
        $this->backup->disk()->put($path, implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * Automations, signup forms and segments cannot be migrated automatically:
     * writes guides to rebuild them (no personal data).
     *
     * @return list<string> written files
     */
    public function pendingReports(string $directory): array
    {
        File::ensureDirectoryExists($directory);
        $written = [];

        $automations = iterator_to_array($this->stagedPayloads('automation'), false);
        $lines = ['# Automatizaciones pendientes de reconstruir', '', 'Mailchimp no permite exportar la lógica de las automatizaciones. Recréalas en el Módulo 6 con estos datos.', ''];
        foreach ($automations as $a) {
            $lines[] = '## '.($a['settings']['title'] ?? $a['id']);
            $lines[] = '';
            $lines[] = '- Estado en Mailchimp: '.($a['status'] ?? '—');
            $lines[] = '- Audiencia: '.($a['recipients']['list_name'] ?? $a['recipients']['list_id'] ?? '—');
            $lines[] = '- Disparador: `'.json_encode($a['trigger_settings'] ?? [], JSON_UNESCAPED_UNICODE).'`';
            $lines[] = '- Correos enviados: '.($a['emails_sent'] ?? '—').' · Creada: '.($a['create_time'] ?? '—');
            $lines[] = '';
        }
        if ($automations === []) {
            $lines[] = '_No se encontraron automatizaciones._';
        }
        File::put($directory.'/automatizaciones_pendientes.md', implode("\n", $lines)."\n");
        $written[] = $directory.'/automatizaciones_pendientes.md';

        $forms = iterator_to_array($this->stagedPayloads('signup_form'), false);
        $lines = ['# Formularios de registro pendientes de reconstruir', '', 'Los formularios de Mailchimp no se pueden migrar; recréalos en «Formularios» con estos textos.', ''];
        foreach ($forms as $f) {
            $lines[] = '## Formulario de '.$this->audienceName((string) ($f['list_id'] ?? ''));
            foreach ((array) ($f['contents'] ?? []) as $c) {
                $lines[] = '- '.($c['section'] ?? 'texto').': '.trim(strip_tags((string) ($c['value'] ?? '')));
            }
            $lines[] = '- URL del formulario en Mailchimp: '.($f['signup_form_url'] ?? '—');
            $lines[] = '';
        }
        if ($forms === []) {
            $lines[] = '_No se encontraron formularios._';
        }
        File::put($directory.'/formularios_pendientes.md', implode("\n", $lines)."\n");
        $written[] = $directory.'/formularios_pendientes.md';

        $segments = iterator_to_array($this->stagedPayloads('segment'), false);
        $lines = ['# Segmentos de Mailchimp', '', 'Los segmentos guardados se reconstruirán como reglas en el Módulo 5. Las etiquetas (segmentos estáticos) ya se migraron como etiquetas.', ''];
        foreach ($segments as $s) {
            $lines[] = '## '.($s['name'] ?? $s['id']).' ('.$this->audienceName((string) ($s['list_id'] ?? '')).')';
            $lines[] = '- Tipo: '.($s['type'] ?? '—').' · Contactos: '.($s['member_count'] ?? '—');
            if (! empty($s['options'])) {
                $lines[] = '- Condiciones: `'.json_encode($s['options'], JSON_UNESCAPED_UNICODE).'`';
            }
            $lines[] = '';
        }
        if ($segments === []) {
            $lines[] = '_No se encontraron segmentos._';
        }
        File::put($directory.'/segmentos_pendientes.md', implode("\n", $lines)."\n");
        $written[] = $directory.'/segmentos_pendientes.md';

        return $written;
    }

    /**
     * Compares counts per audience and status, plus a random sample of contacts, against the backup.
     *
     * @return array{report: string, differences: int}
     */
    public function verify(string $path, int $sampleSize = 50): array
    {
        $differences = 0;
        $lines = ['# Informe de verificación de la migración', '', '- Snapshot: `'.$this->backup->snapshot.'` · Generado: '.now()->toIso8601String(), ''];
        $expectedMap = ['subscribed' => 'subscribed', 'unsubscribed' => 'unsubscribed', 'cleaned' => 'cleaned', 'pending' => 'pending', 'transactional' => 'non_subscribed', 'archived' => 'non_subscribed'];

        $lines[] = '## Conteos por audiencia y estado';
        $lines[] = '';
        $lines[] = '| Audiencia | Estado | En el respaldo de Mailchimp | En la aplicación | Diferencia |';
        $lines[] = '|---|---|---|---|---|';

        $sample = [];
        // Same rule as the loader: a global suppression (bounce/complaint in any audience)
        // turns active statuses into cleaned/unsubscribed everywhere.
        $globallySuppressed = DB::table('suppressions')->where('organization_id', $this->organization->id)
            ->whereNull('audience_id')->pluck('reason', 'email')->all();

        foreach ($this->stagedPayloads('audience') as $list) {
            $audience = Audience::query()->where('mailchimp_id', (string) $list['id'])->first();
            $source = [];

            foreach ($this->stagedPayloads('member', (string) $list['id']) as $member) {
                $status = $expectedMap[(string) ($member['status'] ?? '')] ?? 'non_subscribed';
                $email = Contact::normalizeEmail((string) ($member['email_address'] ?? ''));

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue; // rejected on load (listed in the load report)
                }

                if (isset($globallySuppressed[$email]) && ! in_array($status, ['unsubscribed', 'cleaned'], true)) {
                    $status = in_array($globallySuppressed[$email], ['hard_bounce', 'cleaned'], true) ? 'cleaned' : 'unsubscribed';
                }
                $source[$status] = ($source[$status] ?? 0) + 1;

                if (count($sample) < $sampleSize * 20) {
                    $sample[] = ['list' => (string) $list['id'], 'member' => $member, 'expected' => $status];
                }
            }

            $local = $audience === null ? [] : Subscription::query()->where('audience_id', $audience->id)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();

            foreach (array_unique([...array_keys($source), ...array_keys($local)]) as $status) {
                $diff = ($local[$status] ?? 0) - ($source[$status] ?? 0);
                $lines[] = sprintf('| %s | %s | %d | %d | %s |', str_replace('|', '\|', (string) $list['name']), ContactStatus::tryFrom($status)?->label() ?? $status, $source[$status] ?? 0, $local[$status] ?? 0, $diff === 0 ? '✓' : sprintf('%+d', $diff));
                if ($diff !== 0) {
                    $differences++;
                }
            }
        }

        shuffle($sample);
        $sample = array_slice($sample, 0, $sampleSize);

        $lines = [...$lines, '', '## Muestra aleatoria de '.count($sample).' contactos', '', '| Contacto | Esperado | En la aplicación | Nombre | Resultado |', '|---|---|---|---|---|'];

        foreach ($sample as $item) {
            $email = Contact::normalizeEmail((string) ($item['member']['email_address'] ?? ''));
            $contact = Contact::query()->where('email', $email)->first();
            $audience = Audience::query()->where('mailchimp_id', $item['list'])->first();
            $subscription = ($contact && $audience) ? Subscription::query()->where('audience_id', $audience->id)->where('contact_id', $contact->id)->first() : null;

            $actual = $subscription?->status->value ?? 'no existe';
            $nameOk = $contact !== null && (blank($item['member']['merge_fields']['FNAME'] ?? null) || $contact->first_name === trim((string) $item['member']['merge_fields']['FNAME']));
            // A stricter local status (suppressed elsewhere) is acceptable; a looser one is not.
            $statusOk = $actual === $item['expected'];
            $ok = $statusOk && $nameOk;

            if (! $ok) {
                $differences++;
            }

            $lines[] = sprintf('| %s | %s | %s | %s | %s |', self::mask($email), $item['expected'], $actual, $nameOk ? 'coincide' : 'difiere', $ok ? '✓' : '✗');
        }

        $lines = [...$lines, '', $differences === 0
            ? '**Resultado: sin diferencias.**'
            : "**Resultado: {$differences} diferencias.** Revisa las filas marcadas antes de dar de baja la cuenta de Mailchimp.",
            '',
            'Nota: un contacto puede aparecer como «dado de baja» o «rebotado» en la aplicación aunque esté suscrito en otra audiencia de Mailchimp, si rebotó o se quejó en cualquier audiencia (supresión global).',
        ];

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        return ['report' => $path, 'differences' => $differences];
    }

    private function audienceName(string $listId): string
    {
        return Audience::query()->where('mailchimp_id', $listId)->value('name') ?? $listId;
    }

    /**
     * Streams staged payloads (memory-safe for 100k+ members).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function stagedPayloads(string $resource, ?string $listId = null): \Generator
    {
        $query = DB::table('imports_staging_records')
            ->where('organization_id', $this->organization->id)
            ->where('snapshot', $this->backup->snapshot)
            ->where('resource', $resource)
            ->when($listId !== null, fn ($q) => $q->where('list_id', $listId))
            ->select(['id', 'payload']);

        foreach ($query->lazyById(1000) as $row) {
            yield (int) $row->id => (array) json_decode((string) $row->payload, true);
        }
    }
}
