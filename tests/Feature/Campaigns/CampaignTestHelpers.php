<?php

namespace Tests\Feature\Campaigns;

use App\Enums\ContactStatus;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MailConnection;
use App\Models\Organization;
use App\Models\Subscription;

class CampaignTestHelpers
{
    /**
     * @return array{campaign: Campaign, audience: Audience, connection: MailConnection, contacts: list<Contact>}
     */
    public static function campaignWithSubscribers(Organization $organization, int $subscribers = 3, array $connection = [], array $campaign = []): array
    {
        $organization->update(['physical_address' => '5a Avenida 1-23, Ciudad de Guatemala']);
        $audience = Audience::factory()->create(['permission_reminder' => 'Te suscribiste en nuestra web.']);
        $mailConnection = MailConnection::factory()->create($connection);
        $contacts = [];

        foreach (range(1, $subscribers) as $i) {
            $contact = Contact::factory()->create(['email' => "persona{$i}@example.com", 'first_name' => "Persona{$i}"]);
            Subscription::create(['audience_id' => $audience->id, 'contact_id' => $contact->id, 'status' => ContactStatus::Subscribed, 'consent_at' => now(), 'consent_source' => 'form']);
            $contacts[] = $contact;
        }

        $model = Campaign::factory()->create($campaign + [
            'audience_id' => $audience->id,
            'mail_connection_id' => $mailConnection->id,
        ]);

        return ['campaign' => $model, 'audience' => $audience, 'connection' => $mailConnection, 'contacts' => $contacts];
    }
}
