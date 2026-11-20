<?php

namespace App\Services\Mailchimp;

use Illuminate\Support\Str;
use League\Csv\Reader;
use RuntimeException;
use ZipArchive;

/**
 * Fallback when the API is not available: converts the audience export of the Mailchimp
 * dashboard (ZIP with subscribed/unsubscribed/cleaned/nonsubscribed CSV files) into the same
 * encrypted backup format the API extraction produces, so `mailchimp:load` handles both.
 */
class MailchimpExportConverter
{
    /** Standard Mailchimp export columns → member payload keys. */
    private const array STANDARD = [
        'Email Address' => 'email_address',
        'OPTIN_TIME' => 'timestamp_signup',
        'OPTIN_IP' => 'ip_signup',
        'CONFIRM_TIME' => 'timestamp_opt',
        'CONFIRM_IP' => 'ip_opt',
        'LAST_CHANGED' => 'last_changed',
        'EUID' => 'unique_email_id',
        'UNSUB_REASON' => 'unsubscribe_reason',
        'TAGS' => 'tags',
    ];

    /** Columns that are not merge fields. */
    private const array IGNORED = ['MEMBER_RATING', 'LATITUDE', 'LONGITUDE', 'GMTOFF', 'DSTOFF', 'TIMEZONE', 'CC', 'REGION', 'LEID', 'NOTES',
        'UNSUB_TIME', 'UNSUB_CAMPAIGN_TITLE', 'UNSUB_CAMPAIGN_ID', 'UNSUB_REASON_OTHER', 'CLEAN_TIME', 'CLEAN_CAMPAIGN_TITLE', 'CLEAN_CAMPAIGN_ID'];

    /** Labels of the default merge fields (Spanish and English dashboards). */
    private const array DEFAULT_FIELDS = [
        'first name' => 'FNAME', 'nombre' => 'FNAME',
        'last name' => 'LNAME', 'apellido' => 'LNAME', 'apellidos' => 'LNAME',
        'phone number' => 'PHONE', 'teléfono' => 'PHONE', 'telefono' => 'PHONE',
        'birthday' => 'BIRTHDAY', 'cumpleaños' => 'BIRTHDAY',
        'address' => 'ADDRESS', 'dirección' => 'ADDRESS', 'direccion' => 'ADDRESS',
    ];

    public function __construct(private readonly MailchimpBackup $backup) {}

    /**
     * @return array{list_id: string, members: array<string, int>}
     */
    public function convert(string $path, string $audienceName): array
    {
        $directory = $this->extract($path);
        $listId = 'csv-'.Str::slug($audienceName);
        $files = glob($directory.'/*.csv') ?: [];

        if ($files === []) {
            throw new RuntimeException('No se encontraron archivos CSV en la exportación.');
        }

        $mergeFields = [];
        $members = [];
        $counts = [];

        foreach ($files as $file) {
            $status = $this->statusFromFilename(basename($file));

            if ($status === null) {
                continue;
            }

            $csv = Reader::createFromPath($file, 'r');
            $csv->setHeaderOffset(0);
            $csv->skipInputBOM();

            foreach ($csv->getRecords() as $record) {
                $member = ['status' => $status, 'merge_fields' => [], 'tags' => [], 'source' => 'Exportación CSV de Mailchimp'];

                foreach ($record as $column => $value) {
                    $column = trim((string) $column);
                    $value = trim((string) $value);

                    if (isset(self::STANDARD[$column])) {
                        $member[self::STANDARD[$column]] = $column === 'TAGS' ? $this->tags($value) : $value;
                    } elseif (! in_array($column, self::IGNORED, true) && $column !== '') {
                        $tag = self::DEFAULT_FIELDS[mb_strtolower($column)] ?? Str::upper(Str::limit(Str::slug($column, ''), 10, ''));
                        $mergeFields[$tag] ??= ['tag' => $tag, 'name' => $column, 'type' => $tag === 'BIRTHDAY' ? 'birthday' : 'text'];
                        $member['merge_fields'][$tag] = $value;
                    }
                }

                $email = is_string($member['email_address'] ?? null) ? $member['email_address'] : '';

                if ($email !== '') {
                    $member['id'] = md5(mb_strtolower($email));
                    $members[] = $member;
                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                }
            }
        }

        $this->backup->put('lists/page-0000000', ['lists' => [[
            'id' => $listId,
            'name' => $audienceName,
            'double_optin' => true,
            'stats' => ['member_count' => $counts['subscribed'] ?? 0, 'unsubscribe_count' => $counts['unsubscribed'] ?? 0, 'cleaned_count' => $counts['cleaned'] ?? 0],
        ]], 'total_items' => 1]);
        $this->backup->put("lists/{$listId}/merge_fields/page-0000000", ['merge_fields' => array_values($mergeFields), 'total_items' => count($mergeFields)]);

        foreach (array_chunk($members, MailchimpClient::PAGE_SIZE) as $i => $page) {
            $this->backup->put(sprintf('lists/%s/members/page-%07d', $listId, $i * MailchimpClient::PAGE_SIZE), ['members' => $page, 'total_items' => count($members)]);
        }

        $this->backup->markComplete('csv-export', ['list_id' => $listId, 'members' => $counts]);

        // CSVs extracted from the ZIP are unencrypted personal data: remove them.
        if (! is_dir($path)) {
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }

        return ['list_id' => $listId, 'members' => $counts];
    }

    private function statusFromFilename(string $name): ?string
    {
        $name = mb_strtolower($name);

        return match (true) {
            str_starts_with($name, 'unsubscribed') => 'unsubscribed',
            str_starts_with($name, 'cleaned') => 'cleaned',
            str_starts_with($name, 'nonsubscribed'), str_starts_with($name, 'non_subscribed') => 'transactional',
            str_starts_with($name, 'subscribed') => 'subscribed',
            default => null,
        };
    }

    /**
     * TAGS column looks like: "Socio","Junta directiva"
     *
     * @return list<array{name: string}>
     */
    private function tags(string $value): array
    {
        return array_values(array_map(
            fn ($t) => ['name' => $t],
            array_filter(array_map(fn ($t) => trim((string) $t, " \"'"), str_getcsv($value, ',', '"', '\\'))),
        ));
    }

    private function extract(string $path): string
    {
        if (is_dir($path)) {
            return rtrim($path, '/');
        }

        if (! is_file($path) || mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Indica una carpeta o un archivo .zip exportado de Mailchimp.');
        }

        $target = storage_path('app/private/'.$this->backup->root().'/csv-source');
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP.');
        }

        // Only CSV files at any depth, extracted flat (prevents path traversal).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);

            if (str_ends_with(mb_strtolower($entry), '.csv')) {
                @mkdir($target, 0700, true);
                file_put_contents($target.'/'.basename($entry), (string) $zip->getFromIndex($i));
            }
        }

        $zip->close();

        return $target;
    }
}
