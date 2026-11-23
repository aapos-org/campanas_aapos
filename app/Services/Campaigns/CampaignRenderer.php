<?php

namespace App\Services\Campaigns;

use App\Mail\Transport\MimeMessageBuilder;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\Forms\PublicLinks;

/**
 * Builds the final email for one recipient:
 *  1. click tracking (signed links) and open pixel, when enabled;
 *  2. personalization variables {{ nombre }}, {{ campo }}, {{ nombre | "amigo" }} (always escaped);
 *  3. mandatory footer (physical address + unsubscribe + preferences) if the content lacks it;
 *  4. hidden preheader;
 *  5. List-Unsubscribe / List-Unsubscribe-Post (RFC 8058) headers.
 *
 * Variables are a plain token replacement, never a template engine: user content cannot execute code.
 */
class CampaignRenderer
{
    /** Spanish aliases accepted in templates. */
    public const array ALIASES = [
        'nombre' => 'first_name',
        'apellido' => 'last_name',
        'correo' => 'email',
        'telefono' => 'phone',
        'teléfono' => 'phone',
    ];

    public const array SYSTEM_TAGS = ['enlace_baja', 'unsubscribe_url', 'preferencias', 'preferences_url', 'direccion_fisica', 'nombre_organizacion', 'recordatorio_permiso'];

    public function __construct(
        private readonly PublicLinks $links,
        private readonly TrackingLinks $tracking,
        private readonly MimeMessageBuilder $mime,
    ) {}

    /**
     * @param  Contact|null  $contact  null for a preview with sample data
     */
    public function render(Campaign $campaign, Organization $organization, ?Audience $audience, ?CampaignRecipient $recipient, ?Contact $contact): RenderedEmail
    {
        $unsubscribeUrl = $contact !== null && $audience !== null ? $this->links->unsubscribe($contact, $audience) : '#vista-previa';
        $preferencesUrl = $contact !== null ? $this->links->preferences($contact) : '#vista-previa';

        $values = $this->values($organization, $audience, $contact) + [
            'enlace_baja' => $unsubscribeUrl,
            'unsubscribe_url' => $unsubscribeUrl,
            'preferencias' => $preferencesUrl,
            'preferences_url' => $preferencesUrl,
        ];

        $html = (string) $campaign->html;

        if ($recipient !== null && $campaign->track_clicks) {
            $html = $this->rewriteLinks($html, $recipient);
        }

        if (! preg_match('/\{\{\s*(enlace_baja|unsubscribe_url)\b/i', $html)) {
            $html = $this->injectBeforeBodyEnd($html, $this->footer());
        }

        $html = $this->replace($html, $values, escapeHtml: true);

        if (filled($campaign->preheader)) {
            $preheader = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:transparent;opacity:0">'
                .e($this->replace((string) $campaign->preheader, $values, escapeHtml: false))
                .str_repeat('&#847;&zwnj;&nbsp;', 30).'</div>';
            $html = preg_replace('/(<body\b[^>]*>)/i', '$1'.$preheader, $html, 1) ?? $html;
        }

        if ($recipient !== null && $campaign->track_opens) {
            $pixel = '<img src="'.e($this->tracking->openUrl($recipient)).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0" />';
            $html = $this->injectBeforeBodyEnd($html, $pixel);
        }

        $headers = [];
        if ($contact !== null && $audience !== null) {
            $headers['List-Unsubscribe'] = '<'.$unsubscribeUrl.'>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        $headers['X-Campanas-Campaign'] = (string) $campaign->id;

        return new RenderedEmail(
            subject: $this->replace((string) $campaign->subject, $values, escapeHtml: false),
            html: $html,
            text: $this->mime->htmlToText($html),
            headers: $headers,
        );
    }

    /**
     * Replaces {{ tag }} and {{ tag | "fallback" }}.
     *
     * @param  array<string, string|null>  $values
     */
    public function replace(string $content, array $values, bool $escapeHtml): string
    {
        return (string) preg_replace_callback(
            // Fallback text in double or single quotes (the visual editor rewrites " as ').
            '/\{\{\s*([\p{L}0-9_]+)\s*(?:\|\s*(?:"([^"]*)"|\'([^\']*)\')\s*)?\}\}/u',
            function (array $m) use ($values, $escapeHtml) {
                $key = mb_strtolower($m[1]);
                $key = self::ALIASES[$key] ?? $key;
                $value = $values[$key] ?? null;
                $fallback = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
                $value = ($value === null || $value === '') ? $fallback : $value;

                return $escapeHtml ? e($value) : $value;
            },
            $content,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function values(Organization $organization, ?Audience $audience, ?Contact $contact): array
    {
        $values = [
            'direccion_fisica' => (string) $organization->physical_address,
            'nombre_organizacion' => $organization->name,
            'recordatorio_permiso' => (string) ($audience->permission_reminder ?? ''),
        ];

        if ($contact === null) {
            return $values + ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'email' => 'correo@ejemplo.com', 'phone' => ''];
        }

        $values += [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];

        foreach ($contact->fields as $key => $value) {
            if (! isset($values[$key]) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    private function rewriteLinks(string $html, CampaignRecipient $recipient): string
    {
        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            function (array $m) use ($recipient) {
                $url = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (! preg_match('#^https?://#i', $url)) {
                    return $m[0];
                }

                return $m[1].$m[2].e($this->tracking->clickUrl($recipient, CampaignLink::hashFor($url))).$m[2];
            },
            $html,
        );
    }

    private function footer(): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:32px"><tr><td align="center" style="padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#6b7280">'
            .'{{ recordatorio_permiso }}<br>'
            .'{{ nombre_organizacion }} · {{ direccion_fisica }}<br>'
            .'<a href="{{ enlace_baja }}" style="color:#6b7280">Darme de baja</a> · '
            .'<a href="{{ preferencias }}" style="color:#6b7280">Preferencias</a>'
            .'</td></tr></table>';
    }

    private function injectBeforeBodyEnd(string $html, string $fragment): string
    {
        if (preg_match('/<\/body>/i', $html)) {
            return (string) preg_replace('/<\/body>/i', $fragment.'</body>', $html, 1);
        }

        return $html.$fragment;
    }
}
