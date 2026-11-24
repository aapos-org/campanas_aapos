<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\EventType;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Segment;
use App\Models\Subscription;
use App\Models\Tag;
use App\Services\Campaigns\EventRecorder;
use App\Services\Campaigns\RecipientFreezer;
use App\Services\Segments\SegmentQuery;
use App\Services\Segments\SegmentRules;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->editor = userWithRole(Role::Editor);
    actAsOrganization($this->editor->organization);
    $this->editor->organization->update(['physical_address' => '5a Avenida, Guatemala']);
    $this->audience = Audience::factory()->create();
    MergeField::factory()->create(['key' => 'ciudad', 'label' => 'Ciudad', 'type' => FieldType::Text]);
    MergeField::factory()->create(['key' => 'aportes', 'label' => 'Aportes', 'type' => FieldType::Number]);
    MergeField::factory()->create(['key' => 'cumple', 'label' => 'Cumpleaños', 'type' => FieldType::Birthday]);
    $this->tag = Tag::factory()->create(['name' => 'Voluntario']);

    $this->people = [];
    foreach ([
        ['ana@example.com', ['ciudad' => 'Antigua', 'aportes' => 150, 'cumple' => '03-14'], true, ContactStatus::Subscribed],
        ['beto@example.org', ['ciudad' => 'Xela', 'aportes' => 20, 'cumple' => '07-01'], false, ContactStatus::Subscribed],
        ['carla@example.com', ['ciudad' => 'Antigua'], true, ContactStatus::Unsubscribed],
        ['dani@example.com', [], false, ContactStatus::Subscribed],
    ] as [$email, $fields, $tagged, $status]) {
        $contact = Contact::factory()->create(['email' => $email, 'fields' => $fields]);
        Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $contact->id, 'status' => $status, 'subscribed_at' => now()->subDays(10)]);
        if ($tagged) {
            $contact->tags()->attach($this->tag->id, ['created_at' => now()]);
        }
        $this->people[$email] = $contact;
    }
});

function matching(string $match, array $conditions): array
{
    $query = app(SegmentQuery::class)->base(test()->audience->id);
    app(SegmentQuery::class)->apply($query, $match, app(SegmentRules::class)->validate($conditions));

    return $query->orderBy('c.email')->pluck('c.email')->all();
}

it('filters by tags, custom fields, status and birthdays', function () {
    expect(matching('all', [['field' => 'tag', 'op' => 'has', 'value' => $this->tag->id]]))->toBe(['ana@example.com', 'carla@example.com'])
        ->and(matching('all', [['field' => 'field:ciudad', 'op' => 'is', 'value' => 'antigua'], ['field' => 'status', 'op' => 'is', 'value' => 'subscribed']]))->toBe(['ana@example.com'])
        ->and(matching('any', [['field' => 'field:ciudad', 'op' => 'is', 'value' => 'Xela'], ['field' => 'email', 'op' => 'contains', 'value' => 'dani']]))->toBe(['beto@example.org', 'dani@example.com'])
        ->and(matching('all', [['field' => 'field:aportes', 'op' => 'gt', 'value' => '100']]))->toBe(['ana@example.com'])
        ->and(matching('all', [['field' => 'field:cumple', 'op' => 'month_is', 'value' => 7]]))->toBe(['beto@example.org'])
        ->and(matching('all', [['field' => 'field:ciudad', 'op' => 'blank']]))->toBe(['dani@example.com'])
        ->and(matching('all', [['field' => 'email', 'op' => 'ends_with', 'value' => '.org']]))->toBe(['beto@example.org'])
        ->and(matching('all', [['field' => 'subscribed_at', 'op' => 'within_days', 'value' => 5]]))->toBe([]);
});

it('filters by campaign activity and clicked links', function () {
    ['campaign' => $campaign] = ['campaign' => Campaign::factory()->create(['audience_id' => $this->audience->id, 'status' => CampaignStatus::Sent])];
    $recipient = CampaignRecipient::query()->create([
        'organization_id' => $this->editor->organization_id, 'campaign_id' => $campaign->id, 'contact_id' => $this->people['beto@example.org']->id,
        'email' => 'beto@example.org', 'status' => RecipientStatus::Sent, 'token' => str_repeat('a', 32),
    ]);
    $link = $campaign->links()->create(['url' => 'https://amigosproobras.org/donar', 'hash' => 'h1']);
    app(EventRecorder::class)->record(EventType::Open, $recipient);
    app(EventRecorder::class)->record(EventType::Click, $recipient, linkId: $link->id);

    expect(matching('all', [['field' => 'campaign', 'op' => 'opened', 'value' => $campaign->id]]))->toBe(['beto@example.org'])
        ->and(matching('all', [['field' => 'campaign', 'op' => 'received', 'value' => $campaign->id]]))->toBe(['beto@example.org'])
        ->and(matching('all', [['field' => 'campaign', 'op' => 'not_clicked', 'value' => $campaign->id]]))->toHaveCount(3)
        ->and(matching('all', [['field' => 'link', 'op' => 'url_contains', 'value' => '/donar']]))->toBe(['beto@example.org'])
        ->and(matching('all', [['field' => 'activity', 'op' => 'not_opened_within_days', 'value' => 30]]))->toHaveCount(3);
});

it('treats hostile input as plain values and rejects unknown fields in Spanish', function () {
    expect(matching('all', [['field' => 'field:ciudad', 'op' => 'is', 'value' => "x' OR '1'='1"]]))->toBe([])
        ->and(matching('all', [['field' => 'email', 'op' => 'contains', 'value' => '%']]))->toBe([]);

    expect(fn () => app(SegmentRules::class)->validate([['field' => 'field:ciudad) OR (1=1', 'op' => 'is', 'value' => 'a']]))
        ->toThrow(ValidationException::class, 'Condición 1: el campo no existe.');
    expect(fn () => app(SegmentRules::class)->validate([['field' => 'tag', 'op' => 'contains', 'value' => 'a']]))
        ->toThrow(ValidationException::class, 'la comparación no es válida');
    expect(fn () => app(SegmentRules::class)->validate([]))->toThrow(ValidationException::class, 'Agrega al menos una condición.');
});

it('saves segments, previews counts and sends campaigns only to the segment', function () {
    $conditions = [['field' => 'tag', 'op' => 'has', 'value' => $this->tag->id]];

    $this->actingAs($this->editor)->postJson(route('segments.preview'), ['audience_id' => $this->audience->id, 'match' => 'all', 'conditions' => $conditions])
        ->assertOk()->assertJson(['total' => 2, 'subscribed' => 1]);

    $this->actingAs($this->editor)->post(route('segments.store'), ['name' => 'Voluntarios', 'audience_id' => $this->audience->id, 'match' => 'all', 'conditions' => $conditions])
        ->assertSessionHasNoErrors();
    $segment = Segment::query()->sole();

    $this->actingAs($this->editor)->get(route('segments.index'))
        ->assertInertia(fn ($page) => $page->where('segments.data.0.subscribed', 1)->where('segments.data.0.description', 'Etiqueta tiene «Voluntario»'));

    $campaign = Campaign::factory()->create(['audience_id' => $this->audience->id, 'segment_id' => $segment->id]);
    expect(app(RecipientFreezer::class)->estimate($campaign))->toBe(1)
        ->and(app(RecipientFreezer::class)->freeze($campaign))->toBe(1)
        ->and(CampaignRecipient::query()->sole()->email)->toBe('ana@example.com');
});

it('does not let a campaign use a segment of another audience', function () {
    $other = Audience::factory()->create();
    $segment = Segment::query()->create(['audience_id' => $other->id, 'name' => 'Otro', 'match' => 'all', 'conditions' => [['field' => 'email', 'op' => 'contains', 'value' => '@']]]);
    $campaign = Campaign::factory()->create();

    $this->actingAs($this->editor)->put(route('campaigns.update', $campaign), ['name' => 'X', 'audience_id' => $this->audience->id, 'segment_id' => $segment->id])
        ->assertSessionHasErrors('segment_id');
});
