<?php

use App\Enums\Role;
use App\Models\AiAssistantSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const TEST_KEY = 'sk-ant-api03-prueba-0000000000000000000000-ABCD';

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
    // Password recently confirmed (the section sits behind password confirmation).
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

function fakeModels(int $status = 200): void
{
    Http::fake(['api.anthropic.com/v1/models/*' => Http::response(
        $status === 200 ? ['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5'] : ['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']],
        $status,
    )]);
}

it('stores the key encrypted, verifies it and never exposes it', function () {
    fakeModels();

    $this->actingAs($this->admin)->put(route('admin.ai.key.store'), ['api_key' => TEST_KEY])->assertSessionHasNoErrors();

    $settings = AiAssistantSetting::query()->sole();
    $raw = (string) DB::table('ai_assistant_settings')->value('api_key');
    expect($raw)->not->toContain('ABCD')->not->toContain('sk-ant')
        ->and($settings->api_key)->toBe(TEST_KEY)
        ->and($settings->status)->toBe('active')
        ->and($settings->toArray())->not->toHaveKey('api_key');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/models/claude-opus-5-5'
        && $r->header('x-api-key')[0] === TEST_KEY && $r->header('anthropic-version')[0] === '2026-10-21');

    $page = $this->actingAs($this->admin)->get(route('admin.ai.edit'));
    $page->assertOk()->assertInertia(fn ($p) => $p->where('settings.has_key', true)->where('settings.masked_key', '••••ABCD'));
    expect($page->getContent())->not->toContain(TEST_KEY)->not->toContain('prueba-0000');

    // The audit log keeps at most the last 4 characters.
    $audit = DB::table('audit_logs')->where('event', 'like', 'ai.%')->get()->toJson();
    expect($audit)->toContain('ABCD')->not->toContain(TEST_KEY)->not->toContain('prueba-0000');
});

it('never deletes the key when the form is sent without it, and deletes it on purpose', function () {
    fakeModels();
    $this->actingAs($this->admin)->put(route('admin.ai.key.store'), ['api_key' => TEST_KEY]);

    $this->actingAs($this->admin)->put(route('admin.ai.key.store'), ['api_key' => ''])->assertSessionHasErrors('api_key');
    $this->actingAs($this->admin)->put(route('admin.ai.update'), [
        'enabled' => true, 'model' => 'claude-sonnet-5-5', 'debounce_ms' => 1800, 'min_chars_change' => 20, 'categories' => ['ortografia', 'tono'],
        'max_findings' => 8, 'smart_brevity_enabled' => true, 'smart_brevity_max_words' => 300, 'timeout_seconds' => 30, 'api_key' => '',
    ])->assertSessionHasNoErrors();

    $settings = AiAssistantSetting::query()->sole();
    expect($settings->api_key)->toBe(TEST_KEY)
        ->and($settings->model)->toBe('claude-sonnet-5-5')
        ->and($settings->status)->toBe('unverified') // model changed: verify again
        ->and($settings->activeCategories())->toBe(['ortografia', 'tono', 'smart_brevity']);

    $this->actingAs($this->admin)->delete(route('admin.ai.key.destroy'))->assertRedirect();
    expect(AiAssistantSetting::query()->sole()->hasKey())->toBeFalse()
        ->and(DB::table('audit_logs')->where('event', 'ai.key_deleted')->count())->toBe(1);
});

it('records invalid keys and timeouts as errors visible to the administrator', function () {
    fakeModels(401);
    $this->actingAs($this->admin)->put(route('admin.ai.key.store'), ['api_key' => TEST_KEY]);

    expect(AiAssistantSetting::query()->sole())
        ->status->toBe('error')
        ->last_error->toBe('La API key no es válida o fue revocada.');

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $this->actingAs($this->admin)->post(route('admin.ai.verify'))->assertRedirect();

    expect(AiAssistantSetting::query()->sole()->last_error)->toBe('El proveedor no respondió a tiempo. Intenta de nuevo en unos minutos.');
});

it('is only for administrators and asks for the password again when it expired', function () {
    $this->actingAs(userWithRole(Role::Editor, $this->admin->organization))->get(route('admin.ai.edit'))->assertForbidden();

    $this->withSession(['auth.password_confirmed_at' => time() - 20000]);
    $this->actingAs($this->admin)->put(route('admin.ai.key.store'), ['api_key' => TEST_KEY])->assertRedirect(route('password.confirm'));
    expect(AiAssistantSetting::query()->count())->toBe(0);
});
