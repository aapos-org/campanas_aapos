<?php

use App\Enums\CampaignStatus;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\Template;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runKeyMigration(): void
{
    (require database_path('migrations/2026_11_25_000100_normalize_merge_field_keys.php'))->up();
}

beforeEach(function () {
    // Segments and automations arrive with phase 5; create a minimal shape when absent.
    if (! Schema::hasTable('segments')) {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->jsonb('conditions')->default('[]');
        });
    }

    if (! Schema::hasTable('automations')) {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->jsonb('trigger_config')->default('{}');
        });
        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id');
            $table->jsonb('config')->default('{}');
        });
    }

    $this->org = Organization::factory()->create();
    actAsOrganization($this->org);
});

/**
 * @param  array<string, mixed>  $values
 */
function insertRow(string $table, array $values): int
{
    $columns = Schema::getColumnListing($table);

    return (int) DB::table($table)->insertGetId(array_intersect_key($values + [
        'organization_id' => test()->org->id,
        'audience_id' => Audience::factory()->create()->id,
        'name' => 'Prueba',
        'trigger_type' => 'date_field',
        'position' => 1,
        'type' => 'condition',
        'created_at' => now(),
        'updated_at' => now(),
    ], array_flip($columns)));
}

it('renames old keys everywhere they are referenced', function () {
    $phone = MergeField::factory()->create(['key' => 'n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o', 'label' => 'NÚMERO DE TELÉFONO']);
    $how = MergeField::factory()->create(['key' => 'c_o_m_o_s_u_p_i_s_t_e_d_e_n_o_s_o_t_r_o_s?', 'label' => '¿CÓMO SUPISTE DE NOSOTROS?']);
    $created = MergeField::factory()->create(['key' => 'creado_a_las(_u_t_c+0)', 'label' => 'Creado a las (UTC+0)']);
    $company = MergeField::factory()->create(['key' => 'organizacion/_empresa', 'label' => 'Organización/ Empresa']);
    $city = MergeField::factory()->create(['key' => 'ciudad', 'label' => 'Ciudad']);
    $dup = MergeField::factory()->create(['key' => 'numero_de_telefono', 'label' => 'Otro teléfono']);

    $contact = Contact::factory()->create(['fields' => [
        'n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o' => '5555-1234',
        'creado_a_las(_u_t_c+0)' => '2024-01-01',
        'ciudad' => 'Antigua',
    ]]);

    $segment = insertRow('segments', ['conditions' => json_encode([
        ['field' => 'field:c_o_m_o_s_u_p_i_s_t_e_d_e_n_o_s_o_t_r_o_s?', 'op' => 'eq', 'value' => 'Facebook'],
        ['field' => 'field:ciudad', 'op' => 'eq', 'value' => 'Antigua'],
        ['field' => 'email', 'op' => 'contains', 'value' => 'x'],
    ])]);
    $automation = insertRow('automations', ['trigger_config' => json_encode(['field_key' => 'creado_a_las(_u_t_c+0)', 'days_before' => 0])]);
    $step = insertRow('automation_steps', ['automation_id' => $automation, 'config' => json_encode([
        'conditions' => [['field' => 'field:organizacion/_empresa', 'op' => 'empty', 'value' => null]],
    ])]);

    $html = '<p>Hola {{ nombre }}, tel: {{n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o}} — {{ organizacion/_empresa | "su empresa" }} {{ ciudad }}</p>';
    $draft = Campaign::factory()->create([
        'subject' => 'Para {{ organizacion/_empresa|\'ti\' }}',
        'html' => $html,
        'mjml' => '<mj-text>{{ n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o }}</mj-text>',
        'design' => ['components' => '<p>{{ organizacion/_empresa | "su empresa" }}</p>'],
    ]);
    $sent = Campaign::factory()->create(['status' => CampaignStatus::Sent, 'html' => $html]);
    $historic = Campaign::factory()->create(['is_historic' => true, 'html' => $html]);
    $template = Template::factory()->create(['html' => $html]);

    // Another organization keeps its own keys and values.
    $other = Organization::factory()->create();
    $otherField = MergeField::factory()->for($other)->create(['key' => 'n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o', 'label' => 'NÚMERO DE TELÉFONO']);

    runKeyMigration();

    expect($phone->fresh()->key)->toBe('numero_de_telefono_2')
        ->and($how->fresh()->key)->toBe('como_supiste_de_nosotros')
        ->and($created->fresh()->key)->toBe('creado_a_las_utc_0')
        ->and($company->fresh()->key)->toBe('organizacion_empresa')
        ->and($city->fresh()->key)->toBe('ciudad')
        ->and($dup->fresh()->key)->toBe('numero_de_telefono')
        ->and(MergeField::withoutGlobalScopes()->find($otherField->id)->key)->toBe('numero_de_telefono');

    expect($contact->fresh()->fields)->toEqual([
        'numero_de_telefono_2' => '5555-1234',
        'creado_a_las_utc_0' => '2024-01-01',
        'ciudad' => 'Antigua',
    ]);

    expect(json_decode((string) DB::table('segments')->where('id', $segment)->value('conditions'), true))->toEqual([
        ['field' => 'field:como_supiste_de_nosotros', 'op' => 'eq', 'value' => 'Facebook'],
        ['field' => 'field:ciudad', 'op' => 'eq', 'value' => 'Antigua'],
        ['field' => 'email', 'op' => 'contains', 'value' => 'x'],
    ])
        ->and(json_decode((string) DB::table('automations')->where('id', $automation)->value('trigger_config'), true)['field_key'])->toBe('creado_a_las_utc_0')
        ->and(json_decode((string) DB::table('automation_steps')->where('id', $step)->value('config'), true)['conditions'][0]['field'])->toBe('field:organizacion_empresa');

    $expected = '<p>Hola {{ nombre }}, tel: {{numero_de_telefono_2}} — {{ organizacion_empresa | "su empresa" }} {{ ciudad }}</p>';
    $draft->refresh();
    expect($draft->html)->toBe($expected)
        ->and($draft->subject)->toBe('Para {{ organizacion_empresa|\'ti\' }}')
        ->and($draft->mjml)->toBe('<mj-text>{{ numero_de_telefono_2 }}</mj-text>')
        ->and($draft->design)->toBe(['components' => '<p>{{ organizacion_empresa | "su empresa" }}</p>'])
        ->and($template->fresh()->html)->toBe($expected)
        ->and($sent->fresh()->html)->toBe($html)
        ->and($historic->fresh()->html)->toBe($html);
});

it('is idempotent', function () {
    MergeField::factory()->create(['key' => 'n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o', 'label' => 'NÚMERO DE TELÉFONO']);
    MergeField::factory()->create(['key' => 'zona_10', 'label' => 'Zona 10']);
    MergeField::factory()->create(['key' => 'email_mc', 'label' => 'Email']);
    $contact = Contact::factory()->create(['fields' => ['n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o' => '5555-1234']]);
    $campaign = Campaign::factory()->create(['html' => '<p>{{ n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o }}</p>']);

    runKeyMigration();
    $keys = MergeField::query()->orderBy('id')->pluck('key')->all();
    $fields = $contact->fresh()->fields;
    $html = $campaign->fresh()->html;

    runKeyMigration();

    expect($keys)->toBe(['numero_de_telefono', 'zona_10', 'email_mc'])
        ->and(MergeField::query()->orderBy('id')->pluck('key')->all())->toBe($keys)
        ->and($contact->fresh()->fields)->toBe($fields)->toBe(['numero_de_telefono' => '5555-1234'])
        ->and($campaign->fresh()->html)->toBe($html)->toBe('<p>{{ numero_de_telefono }}</p>');
});
