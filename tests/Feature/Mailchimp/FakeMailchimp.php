<?php

namespace Tests\Feature\Mailchimp;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory Mailchimp API used by the migration tests.
 */
class FakeMailchimp
{
    /** Built at runtime so no key-shaped literal is committed (GitHub push protection). */
    public static function key(): string
    {
        return str_repeat('ab12', 8).'-us21';
    }

    /** @var array<string, list<array<string, mixed>>> */
    public array $members = [];

    public static function install(?self $api = null): self
    {
        $api ??= new self;
        config(['services.mailchimp.api_key' => self::key()]);

        Http::fake(fn (Request $request) => $api->respond($request));

        return $api;
    }

    public function __construct()
    {
        $this->members['list1'] = [
            $this->member('ana@example.com', 'subscribed', ['FNAME' => 'Ana', 'LNAME' => 'López', 'PHONE' => '5555-1234', 'MMERGE5' => '03/31', 'MMERGE6' => 'Socio'], [['id' => 1, 'name' => 'VIP']], optin: '2024-05-01T10:00:00+00:00', ip: '190.56.1.1'),
            $this->member('BETO@example.com', 'unsubscribed', ['FNAME' => 'Beto']),
            $this->member('carla@example.com', 'cleaned', ['FNAME' => 'Carla']),
            $this->member('dario@example.com', 'pending', ['FNAME' => 'Darío'], signup: '2024-06-01T10:00:00+00:00'),
            $this->member('eva@example.com', 'unsubscribed', [], reason: 'Abuse report'),
            $this->member('fede@example.com', 'archived', []),
            $this->member('no-es-correo', 'subscribed', []),
        ];
        $this->members['list2'] = [
            // Carla bounced in list1 (cleaned) but is "subscribed" in list2: global suppression must win.
            $this->member('carla@example.com', 'subscribed', ['FNAME' => 'Carla'], optin: '2023-01-01T00:00:00+00:00'),
            $this->member('gabi@example.com', 'subscribed', ['FNAME' => 'Gabi'], optin: '2023-01-01T00:00:00+00:00'),
        ];
    }

    /**
     * @param  array<string, mixed>  $merge
     * @param  list<array<string, mixed>>  $tags
     * @return array<string, mixed>
     */
    public function member(string $email, string $status, array $merge, array $tags = [], ?string $optin = null, ?string $ip = null, ?string $signup = null, ?string $reason = null): array
    {
        return array_filter([
            'id' => md5(strtolower($email)),
            'email_address' => $email,
            'unique_email_id' => substr(md5($email), 0, 10),
            'status' => $status,
            'merge_fields' => $merge,
            'tags' => $tags,
            'timestamp_opt' => $optin,
            'ip_opt' => $ip,
            'timestamp_signup' => $signup,
            'last_changed' => '2025-01-15T12:00:00+00:00',
            'source' => 'Hosted form',
            'language' => 'es',
            'unsubscribe_reason' => $reason,
        ], fn ($v) => $v !== null);
    }

    private function respond(Request $request): mixed
    {
        if ($request->method() !== 'GET') {
            return Http::response(['detail' => 'not allowed in tests'], 405);
        }

        $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
        $path = (string) preg_replace('#^3\.0/#', '', $path);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $offset = (int) ($query['offset'] ?? 0);
        $count = (int) ($query['count'] ?? 1000);

        $page = fn (string $key, array $items) => Http::response([$key => array_slice($items, $offset, $count), 'total_items' => count($items)]);

        return match (true) {
            $path === 'lists' => $page('lists', [
                ['id' => 'list1', 'web_id' => 11, 'name' => 'Boletín', 'double_optin' => true, 'permission_reminder' => 'Te suscribiste en la web', 'campaign_defaults' => ['from_name' => 'AAPOS', 'from_email' => 'info@example.org'], 'stats' => ['member_count' => 2, 'unsubscribe_count' => 2, 'cleaned_count' => 1]],
                ['id' => 'list2', 'web_id' => 22, 'name' => 'Congreso', 'double_optin' => false, 'stats' => ['member_count' => 2]],
            ]),
            $path === 'lists/list1/merge-fields' => $page('merge_fields', [
                ['tag' => 'FNAME', 'name' => 'First Name', 'type' => 'text'],
                ['tag' => 'MMERGE5', 'name' => 'Cumpleaños', 'type' => 'birthday'],
                ['tag' => 'MMERGE6', 'name' => 'Tipo', 'type' => 'dropdown', 'options' => ['choices' => ['Socio', 'Invitado']]],
            ]),
            $path === 'lists/list2/merge-fields' => $page('merge_fields', [['tag' => 'FNAME', 'name' => 'First Name', 'type' => 'text']]),
            str_ends_with($path, '/segments') => $page('segments', [['id' => 9, 'name' => 'Activos', 'type' => 'saved', 'member_count' => 10, 'list_id' => 'list1', 'options' => ['match' => 'all']]]),
            str_ends_with($path, '/signup-forms') => Http::response(['signup_forms' => [['list_id' => 'list1', 'signup_form_url' => 'https://eepurl.com/x', 'contents' => [['section' => 'signup_message', 'value' => '<p>Suscríbete</p>']]]]]),
            preg_match('#^lists/(list\d)/members$#', $path, $m) === 1 => $page('members', $this->members[$m[1]] ?? []),
            $path === 'templates' => $page('templates', [['id' => 7, 'name' => 'Plantilla base', 'type' => 'user']]),
            $path === 'templates/7/default-content' => Http::response(['sections' => ['body' => '<p>Hola</p>']]),
            $path === 'automations' => Http::response(['automations' => [['id' => 'a1', 'status' => 'sending', 'settings' => ['title' => 'Bienvenida'], 'recipients' => ['list_id' => 'list1'], 'trigger_settings' => ['workflow_type' => 'welcomeSeries']]]]),
            $path === 'campaigns' => $page('campaigns', [['id' => 'c1', 'settings' => ['title' => 'Boletín enero', 'subject_line' => 'Hola'], 'send_time' => '2025-01-10T15:00:00+00:00']]),
            str_starts_with($path, 'campaigns/c1/content') => Http::response(['html' => '<p>Contenido</p>']),
            $path === 'reports/c1' => Http::response(['id' => 'c1', 'emails_sent' => 2, 'opens' => ['opens_total' => 3]]),
            str_starts_with($path, 'reports/c1/click-details') => $page('urls_clicked', [['url' => 'https://example.org', 'total_clicks' => 4]]),
            str_starts_with($path, 'reports/c1/unsubscribed') => $page('unsubscribes', []),
            str_starts_with($path, 'reports/c1/sent-to') => $page('sent_to', [['email_address' => 'ana@example.com', 'status' => 'sent']]),
            str_starts_with($path, 'reports/c1/email-activity') => $page('emails', [['email_address' => 'ana@example.com', 'activity' => [['action' => 'open']]]]),
            default => Http::response(['detail' => "unknown {$path}"], 404),
        };
    }
}
