<?php

use App\Enums\CampaignStatus;
use App\Enums\EventType;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\EventRecorder;
use App\Services\Campaigns\PreSendChecklist;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm', 'TransactionID' => 't'])]);
});

function sentSubjects(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'emails/transactional'))
        ->map(fn ($pair) => [$pair[0]['Recipients']['To'][0], $pair[0]['Content']['Subject']])
        ->values()->all();
}

function abCampaign(object $test, array $attributes = []): Campaign
{
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($test->org, 10, campaign: $attributes + [
        'subject' => 'Asunto A', 'ab_type' => 'subject', 'ab_sample_percent' => 20, 'ab_metric' => 'clicks', 'ab_wait_hours' => 4,
    ]);
    $campaign->variants()->create(['label' => 'A']);
    $campaign->variants()->create(['label' => 'B', 'subject' => 'Asunto B']);

    return $campaign;
}

it('sends the sample split between variants, waits, then sends the winner to the rest', function () {
    $campaign = abCampaign($this);

    app(CampaignSender::class)->start($campaign);

    $campaign->refresh();
    $sample = sentSubjects();
    expect($campaign->status)->toBe(CampaignStatus::Testing)
        ->and($sample)->toHaveCount(2)
        ->and(collect($sample)->pluck(1)->sort()->values()->all())->toBe(['Asunto A', 'Asunto B'])
        ->and(CampaignRecipient::query()->where('status', 'pending')->whereNull('variant_id')->count())->toBe(8);

    // Version B gets a click.
    $b = CampaignRecipient::query()->whereHas('variant', fn ($q) => $q->where('label', 'B'))->sole();
    app(EventRecorder::class)->record(EventType::Click, $b);

    $this->artisan('campaigns:decide-ab')->assertSuccessful();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Testing); // still waiting

    $this->travel(5)->hours();
    $this->artisan('campaigns:decide-ab')->assertSuccessful();

    $campaign->refresh();
    $rest = array_slice(sentSubjects(), 2);
    expect($campaign->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->ab_winner_variant_id)->toBe($b->variant_id)
        ->and($rest)->toHaveCount(8)
        ->and(collect($rest)->pluck(1)->unique()->values()->all())->toBe(['Asunto B'])
        ->and($campaign->sent_count)->toBe(10);
});

it('lets a sender pick the winner early, with ties going to version A', function () {
    $campaign = abCampaign($this, ['ab_metric' => 'opens']);
    app(CampaignSender::class)->start($campaign);

    $this->actingAs($this->admin)->post(route('campaigns.ab-winner', $campaign))->assertRedirect();

    $campaign->refresh();
    expect($campaign->variants()->find($campaign->ab_winner_variant_id)->label)->toBe('A')
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and(collect(array_slice(sentSubjects(), 2))->pluck(1)->unique()->values()->all())->toBe(['Asunto A']);
});

it('sends each variant its own content in a content test', function () {
    $campaign = abCampaign($this, ['ab_type' => 'content', 'html' => '<p>Contenido A</p>']);
    $campaign->variants()->where('label', 'B')->update(['subject' => null, 'html' => '<p>Contenido B</p>']);

    app(CampaignSender::class)->start($campaign);

    $htmls = Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), 'emails/transactional'))
        ->map(fn ($p) => collect($p[0]['Content']['Body'])->firstWhere('ContentType', 'HTML')['Content'])->values();
    expect($htmls->filter(fn ($h) => str_contains($h, 'Contenido A')))->toHaveCount(1)
        ->and($htmls->filter(fn ($h) => str_contains($h, 'Contenido B')))->toHaveCount(1);
});

it('blocks sending an A/B test whose version B is incomplete', function () {
    $campaign = abCampaign($this);
    $campaign->variants()->where('label', 'B')->update(['subject' => null]);

    $checks = app(PreSendChecklist::class)->run($campaign, $this->org);

    expect(collect($checks)->firstWhere('key', 'ab'))->toMatchArray(['status' => 'error', 'message' => 'Prueba A/B: falta el asunto de la versión B.']);
});

it('saves the A/B settings from the campaign form', function () {
    ['campaign' => $campaign, 'audience' => $audience] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);

    $this->actingAs($this->admin)->put(route('campaigns.update', $campaign), [
        'name' => 'Boletín', 'audience_id' => $audience->id, 'ab_type' => 'subject', 'ab_sample_percent' => 30,
        'ab_metric' => 'opens', 'ab_wait_hours' => 2, 'variant_b_subject' => 'Otro asunto',
    ])->assertSessionHasNoErrors();

    expect($campaign->refresh()->ab_sample_percent)->toBe(30)
        ->and($campaign->variants()->pluck('subject', 'label')->all())->toBe(['A' => null, 'B' => 'Otro asunto']);

    $this->actingAs($this->admin)->put(route('campaigns.update', $campaign), ['name' => 'Boletín', 'audience_id' => $audience->id, 'ab_type' => null])
        ->assertSessionHasNoErrors();
    expect($campaign->refresh()->ab_type)->toBeNull()->and($campaign->variants()->count())->toBe(0);
});
