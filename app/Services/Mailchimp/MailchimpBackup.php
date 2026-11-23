<?php

namespace App\Services\Mailchimp;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Raw Mailchimp responses saved as encrypted JSON files (APP_KEY, AES-256) under
 * storage/app/private/mailchimp/{snapshot}/, plus an unencrypted manifest without
 * personal data. Re-running the extraction reads existing files instead of calling the API.
 *
 * IMPORTANT: the backup can only be decrypted with the same APP_KEY.
 */
class MailchimpBackup
{
    public function __construct(public readonly string $snapshot = 'default')
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $snapshot)) {
            throw new \InvalidArgumentException('Nombre de snapshot inválido (solo minúsculas, números, - y _).');
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function root(): string
    {
        return 'mailchimp/'.$this->snapshot;
    }

    public function has(string $key): bool
    {
        return $this->disk()->exists($this->path($key));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $key, array $data): void
    {
        // Atomic: write a temporary file and rename it, so an interrupted run never
        // leaves a half-written file that would look complete on resume.
        $path = $this->path($key);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        $this->disk()->put($temporary, Crypt::encryptString((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        $this->disk()->move($temporary, $path);
    }

    /**
     * Like get(), but returns null (and removes the file) when it cannot be decrypted or decoded,
     * so the extraction downloads it again.
     *
     * @return array<string, mixed>|null
     */
    public function tryGet(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        try {
            return $this->get($key);
        } catch (DecryptException|\JsonException) {
            $this->disk()->delete($this->path($key));

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(Crypt::decryptString((string) $this->disk()->get($this->path($key))), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Keys (without extension) under a prefix, sorted.
     *
     * @return list<string>
     */
    public function keys(string $prefix): array
    {
        $base = $this->root().'/';
        $files = $this->disk()->allFiles($base.trim($prefix, '/'));
        sort($files);

        return array_values(array_map(
            fn (string $file) => substr($file, strlen($base), -strlen('.json.enc')),
            array_filter($files, fn (string $f) => str_ends_with($f, '.json.enc')),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $path = $this->root().'/manifest.json';

        /** @var array<string, mixed> */
        return $this->disk()->exists($path)
            ? (array) json_decode((string) $this->disk()->get($path), true)
            : ['snapshot' => $this->snapshot, 'resources' => []];
    }

    /**
     * @param  array<string, mixed>  $info  counts / dates only, never personal data
     */
    public function markComplete(string $resource, array $info = []): void
    {
        $manifest = $this->manifest();
        $manifest['resources'][$resource] = $info + ['completed_at' => now()->toIso8601String()];
        $manifest['updated_at'] = now()->toIso8601String();

        $this->disk()->put($this->root().'/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function isComplete(string $resource): bool
    {
        return isset($this->manifest()['resources'][$resource]);
    }

    private function path(string $key): string
    {
        return $this->root().'/'.trim($key, '/').'.json.enc';
    }
}
