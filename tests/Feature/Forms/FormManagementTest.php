<?php

use App\Enums\Role;
use App\Models\Audience;
use App\Models\Form;

beforeEach(function () {
    $this->editor = userWithRole(Role::Editor);
    actAsOrganization($this->editor->organization);
    $this->audience = Audience::factory()->create();
});

function formPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Web',
        'audience_id' => test()->audience->id,
        'is_active' => true,
        'fields' => [['key' => 'first_name', 'label' => 'Tu nombre', 'required' => true]],
        'texts' => ['title' => 'Únete'],
        'style' => ['primary_color' => '#112233'],
        'double_opt_in' => null,
        'captcha' => false,
        'tags' => ['Web'],
        'redirect_url' => null,
    ];
}

it('creates a form, always including the email first', function () {
    $this->actingAs($this->editor)->post(route('forms.store'), formPayload())->assertSessionHasNoErrors();

    $form = Form::query()->sole();
    expect($form->fields[0])->toBe(['key' => 'email', 'label' => 'Correo electrónico', 'required' => true])
        ->and($form->fields[1]['label'])->toBe('Tu nombre')
        ->and($form->slug)->toMatch('/^[a-z0-9]{12}$/')
        ->and($form->text('title'))->toBe('Únete')
        ->and($form->color('primary_color'))->toBe('#112233');

    $this->actingAs($this->editor)->get(route('forms.edit', $form))->assertOk()
        ->assertInertia(fn ($page) => $page->where('embed.script', fn ($s) => str_contains($s, '/f/'.$form->slug.'/embed.js')));
});

it('only accepts https redirect URLs, valid colors and known fields', function () {
    $this->actingAs($this->editor)->post(route('forms.store'), formPayload([
        'redirect_url' => 'javascript:alert(1)',
        'style' => ['primary_color' => 'red;}body{display:none'],
        'fields' => [['key' => 'password', 'label' => 'x', 'required' => false]],
    ]))->assertSessionHasErrors(['redirect_url', 'style.primary_color', 'fields.0.key']);
});

it('forbids form management to read-only users', function () {
    $viewer = userWithRole(Role::Viewer, $this->editor->organization);

    $this->actingAs($viewer)->get(route('forms.index'))->assertForbidden();
});

it('stops serving inactive forms', function () {
    $this->actingAs($this->editor)->post(route('forms.store'), formPayload(['is_active' => false]));

    $this->get(route('forms.public.show', ['slug' => Form::query()->sole()->slug]))->assertNotFound();
});

it('stops serving deleted forms and forms of deleted audiences', function () {
    $this->actingAs($this->editor)->post(route('forms.store'), formPayload());
    $form = Form::query()->sole();
    $this->get(route('forms.public.show', ['slug' => $form->slug]))->assertOk();

    $this->audience->delete();
    $this->get(route('forms.public.show', ['slug' => $form->slug]))->assertNotFound();

    $this->audience->restore();
    $form->delete();
    $this->get(route('forms.public.show', ['slug' => $form->slug]))->assertNotFound();
});

it('lists forms that inherit double opt-in from their audience', function () {
    $this->audience->update(['double_opt_in' => true]);
    $this->actingAs($this->editor)->post(route('forms.store'), formPayload())->assertSessionHasNoErrors();

    $this->actingAs($this->editor)->get(route('forms.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('forms.0.double_opt_in', true));
});
