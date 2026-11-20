<?php

namespace Database\Seeders;

use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Tag;
use App\Services\Contacts\SuppressionService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * Local demo data: one audience with contacts in every status, custom fields and tags.
 */
class DemoContactsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'aapos')->firstOrFail();

        app(CurrentOrganization::class)->runAs($organization, function () {
            MergeField::query()->firstOrCreate(['key' => 'ciudad'], ['label' => 'Ciudad', 'type' => FieldType::Select, 'options' => ['Guatemala', 'Antigua Guatemala', 'Quetzaltenango', 'Escuintla'], 'position' => 1]);
            MergeField::query()->firstOrCreate(['key' => 'cumpleanos'], ['label' => 'Cumpleaños', 'type' => FieldType::Birthday, 'position' => 2]);

            $audience = Audience::query()->firstOrCreate(['name' => 'Boletín AAPOS'], [
                'description' => 'Boletín mensual para socios y simpatizantes.',
                'default_from_name' => 'AAPOS',
                'default_from_email' => 'boletin@aapos.test',
                'permission_reminder' => 'Recibes este correo porque te suscribiste al boletín de AAPOS.',
                'double_opt_in' => true,
            ]);
            Audience::query()->firstOrCreate(['name' => 'Congreso 2026'], ['description' => 'Inscritos al congreso anual.']);

            $tags = collect(['Socio', 'Junta directiva', 'Voluntario', 'Congreso 2026'])
                ->map(fn ($name) => Tag::query()->firstOrCreate(['name' => $name]));

            $cities = ['Guatemala', 'Antigua Guatemala', 'Quetzaltenango', 'Escuintla'];
            $statuses = [
                ...array_fill(0, 40, ContactStatus::Subscribed),
                ...array_fill(0, 6, ContactStatus::Unsubscribed),
                ...array_fill(0, 3, ContactStatus::Cleaned),
                ...array_fill(0, 4, ContactStatus::Pending),
                ...array_fill(0, 7, ContactStatus::NonSubscribed),
            ];

            foreach ($statuses as $i => $status) {
                $contact = Contact::query()->firstOrCreate(
                    ['email' => sprintf('contacto%02d@ejemplo.test', $i + 1)],
                    [
                        'first_name' => fake('es_ES')->firstName(),
                        'last_name' => fake('es_ES')->lastName(),
                        'fields' => ['ciudad' => $cities[$i % 4], 'cumpleanos' => sprintf('%02d-%02d', ($i % 12) + 1, ($i % 28) + 1)],
                    ],
                );

                Subscription::query()->firstOrCreate(
                    ['audience_id' => $audience->id, 'contact_id' => $contact->id],
                    [
                        'organization_id' => $contact->organization_id,
                        'status' => $status,
                        'subscribed_at' => $status === ContactStatus::Subscribed ? now()->subDays(400 - $i * 5) : null,
                        'consent_at' => $status === ContactStatus::Subscribed ? now()->subDays(400 - $i * 5) : null,
                        'consent_source' => $status === ContactStatus::Subscribed ? 'form' : null,
                        'consent_ip' => $status === ContactStatus::Subscribed ? '190.56.10.'.($i + 1) : null,
                        'unsubscribed_at' => $status->isTerminal() ? now()->subDays(30) : null,
                    ],
                );

                $contact->tags()->syncWithoutDetaching($tags->random(($i % 3) + 1)->pluck('id'));

                if ($status === ContactStatus::Cleaned) {
                    app(SuppressionService::class)->suppress($contact->email, SuppressionReason::HardBounce, null, 'demo');
                } elseif ($status === ContactStatus::Unsubscribed) {
                    app(SuppressionService::class)->suppress($contact->email, SuppressionReason::Unsubscribed, $audience, 'demo');
                }
            }
        });
    }
}
