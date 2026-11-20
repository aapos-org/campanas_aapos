<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Public signup form of an audience (hosted page, iframe, JS and HTML embeds).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $audience_id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property list<array{key: string, label: string, required: bool}> $fields
 * @property array<string, string> $texts
 * @property array<string, string> $style
 * @property bool|null $double_opt_in
 * @property bool $captcha
 * @property list<string> $tags
 * @property string|null $redirect_url
 * @property int $submissions_count
 * @property-read Audience $audience
 */
#[Fillable(['organization_id', 'audience_id', 'name', 'slug', 'is_active', 'fields', 'texts', 'style', 'double_opt_in', 'captcha', 'tags', 'redirect_url'])]
class Form extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const array DEFAULT_TEXTS = [
        'title' => 'Suscríbete a nuestro boletín',
        'description' => 'Recibe nuestras noticias en tu correo. Puedes darte de baja cuando quieras.',
        'button' => 'Suscribirme',
        'success' => '¡Listo! Ya estás suscrito.',
        'pending' => 'Casi listo: te enviamos un correo. Abre el enlace para confirmar tu suscripción.',
        'confirmed' => '¡Gracias! Tu suscripción está confirmada.',
        'already' => 'Ya estabas suscrito. ¡Gracias por tu interés!',
        'confirmation_subject' => 'Confirma tu suscripción',
        'confirmation_intro' => 'Recibimos una solicitud para suscribir este correo. Si fuiste tú, confírmala con el botón. Si no, ignora este mensaje.',
    ];

    public const array DEFAULT_STYLE = [
        'primary_color' => '#0f766e',
        'background_color' => '#ffffff',
        'text_color' => '#111827',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tags' => '[]',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $form): void {
            $form->slug ??= Str::lower(Str::random(12));
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fields' => 'array',
            'texts' => 'array',
            'style' => 'array',
            'double_opt_in' => 'boolean',
            'captcha' => 'boolean',
            'tags' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * @return BelongsTo<Audience, $this>
     */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function text(string $key): string
    {
        return (string) (($this->texts[$key] ?? null) ?: self::DEFAULT_TEXTS[$key] ?? '');
    }

    public function color(string $key): string
    {
        $value = (string) ($this->style[$key] ?? '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : self::DEFAULT_STYLE[$key];
    }

    public function usesDoubleOptIn(): bool
    {
        return $this->double_opt_in ?? $this->audience->double_opt_in;
    }
}
