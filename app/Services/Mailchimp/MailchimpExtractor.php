<?php

namespace App\Services\Mailchimp;

use Closure;

/**
 * Downloads everything needed for the migration into the encrypted backup.
 * Idempotent and resumable: pages already saved are read from disk, not requested again.
 */
class MailchimpExtractor
{
    /** @var Closure(string): void */
    private Closure $output;

    public function __construct(
        private readonly MailchimpClient $client,
        private readonly MailchimpBackup $backup,
    ) {
        $this->output = fn (string $line) => null;
    }

    /**
     * @param  Closure(string): void  $output
     */
    public function onProgress(Closure $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * @param  array{campaigns?: bool, activity?: bool, campaigns_since?: string|null}  $options
     */
    public function run(array $options = []): void
    {
        $withCampaigns = $options['campaigns'] ?? true;
        $withActivity = $options['activity'] ?? true;

        $lists = $this->collect('lists', 'lists', 'lists');
        ($this->output)(sprintf('Audiencias: %d', count($lists)));

        foreach ($lists as $list) {
            $id = (string) $list['id'];
            $name = (string) ($list['name'] ?? $id);

            $this->collect("lists/{$id}/merge-fields", "lists/{$id}/merge_fields", 'merge_fields');
            $this->collect("lists/{$id}/segments", "lists/{$id}/segments", 'segments');
            $this->collect("lists/{$id}/signup-forms", "lists/{$id}/signup_forms", 'signup_forms', paginated: false);

            $members = $this->collect(
                "lists/{$id}/members",
                "lists/{$id}/members",
                'members',
                ['exclude_fields' => 'members._links,_links'],
                keepItems: false,
                onPage: fn (int $offset, int $total) => ($this->output)(sprintf('  «%s»: contactos %d / %d', $name, min($offset + MailchimpClient::PAGE_SIZE, $total), $total)),
            );

            ($this->output)(sprintf('«%s»: %d contactos descargados', $name, $members));
        }

        $this->collect('templates', 'templates', 'templates', ['type' => 'user']);
        foreach ($this->items('templates', 'templates') as $template) {
            $this->single("templates/{$template['id']}/default-content", "templates/{$template['id']}/default_content");
        }

        $this->collect('automations', 'automations', 'automations', paginated: false);

        if ($withCampaigns) {
            $campaigns = $this->collect('campaigns', 'campaigns', 'campaigns', [
                'status' => 'sent',
                'since_send_time' => $options['campaigns_since'] ?? null,
                'sort_field' => 'send_time',
                'sort_dir' => 'ASC',
            ]);
            ($this->output)(sprintf('Campañas enviadas: %d', count($campaigns)));

            foreach ($campaigns as $i => $campaign) {
                $cid = (string) $campaign['id'];
                ($this->output)(sprintf('  Campaña %d/%d: %s', $i + 1, count($campaigns), (string) ($campaign['settings']['title'] ?? $cid)));

                $this->single("campaigns/{$cid}/content", "campaigns/{$cid}/content");
                $this->single("reports/{$cid}", "reports/{$cid}/summary");
                $this->collect("reports/{$cid}/click-details", "reports/{$cid}/click_details", 'urls_clicked', keepItems: false);
                $this->collect("reports/{$cid}/unsubscribed", "reports/{$cid}/unsubscribed", 'unsubscribes', keepItems: false);

                if ($withActivity) {
                    $this->collect("reports/{$cid}/sent-to", "reports/{$cid}/sent_to", 'sent_to', ['exclude_fields' => 'sent_to._links,_links'], keepItems: false);
                    $this->collect("reports/{$cid}/email-activity", "reports/{$cid}/email_activity", 'emails', ['exclude_fields' => 'emails._links,_links'], keepItems: false);
                }
            }
        }

        ($this->output)('Extracción completa. Respaldo en storage/app/private/'.$this->backup->root());
    }

    /**
     * Reads (from backup) or downloads every page of a collection.
     *
     * @param  array<string, scalar|null>  $query
     * @param  Closure(int, int): void|null  $onPage
     * @return ($keepItems is true ? list<array<string, mixed>> : int)
     */
    private function collect(
        string $apiPath,
        string $key,
        string $itemsKey,
        array $query = [],
        bool $paginated = true,
        bool $keepItems = true,
        ?Closure $onPage = null,
    ): array|int {
        $items = [];
        $count = 0;

        if (! $paginated) {
            $data = $this->single($apiPath, "{$key}/all", $query);
            $items = array_values((array) ($data[$itemsKey] ?? []));
            $this->backup->markComplete($key, ['items' => count($items)]);

            return $keepItems ? $items : count($items);
        }

        $offset = 0;
        do {
            $pageKey = sprintf('%s/page-%07d', $key, $offset);

            if ($this->backup->has($pageKey)) {
                $page = $this->backup->get($pageKey);
            } else {
                $page = $this->client->get($apiPath, $query + ['count' => MailchimpClient::PAGE_SIZE, 'offset' => $offset]);
                unset($page['_links']);
                $this->backup->put($pageKey, $page);
            }

            /** @var list<array<string, mixed>> $pageItems */
            $pageItems = array_values((array) ($page[$itemsKey] ?? []));
            $total = (int) ($page['total_items'] ?? count($pageItems));
            $count += count($pageItems);

            if ($keepItems) {
                array_push($items, ...$pageItems);
            }

            if ($onPage !== null) {
                $onPage($offset, $total);
            }

            $offset += MailchimpClient::PAGE_SIZE;
        } while ($pageItems !== [] && $offset < $total);

        $this->backup->markComplete($key, ['items' => $count, 'total_reported' => $total]);

        return $keepItems ? $items : $count;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function single(string $apiPath, string $key, array $query = []): array
    {
        if ($this->backup->has($key)) {
            return $this->backup->get($key);
        }

        $data = $this->client->get($apiPath, $query);
        unset($data['_links']);
        $this->backup->put($key, $data);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $key, string $itemsKey): array
    {
        $items = [];
        foreach ($this->backup->keys($key) as $pageKey) {
            if (str_contains($pageKey, '/page-')) {
                array_push($items, ...array_values((array) ($this->backup->get($pageKey)[$itemsKey] ?? [])));
            }
        }

        return $items;
    }
}
