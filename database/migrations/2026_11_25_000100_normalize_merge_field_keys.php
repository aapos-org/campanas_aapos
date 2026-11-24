<?php

use App\Models\MergeField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Renames custom field keys created by the old generator (`Str::snake(Str::ascii($label))`,
 * which split uppercase text letter by letter and kept punctuation) to the normalized format
 * (see MergeField::keyFor) and updates every place that references them.
 *
 * Idempotent: keys already in the normalized format are left alone. Sent and historic
 * campaigns are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('merge_fields')->distinct()->orderBy('organization_id')->pluck('organization_id') as $organizationId) {
                $renames = $this->renameFields((int) $organizationId);

                if ($renames !== []) {
                    $this->renameReferences((int) $organizationId, $renames);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible: the old keys were not valid variable names.
    }

    /**
     * @return array<string, string> old key => new key
     */
    private function renameFields(int $organizationId): array
    {
        $fields = DB::table('merge_fields')->where('organization_id', $organizationId)->orderBy('id')->get(['id', 'key', 'label', 'mailchimp_tag']);

        $stale = $fields->filter(fn (object $f): bool => $this->needsRename((string) $f->key, (string) $f->label));

        if ($stale->isEmpty()) {
            return [];
        }

        // Every current key stays taken, so a new key never collides with an old one mid-update.
        $taken = $fields->pluck('key')->map(fn ($k): string => (string) $k)->all();
        $renames = [];

        foreach ($stale as $field) {
            $new = MergeField::keyFor((string) $field->label, $taken, $field->mailchimp_tag !== null ? (string) $field->mailchimp_tag : null);
            $taken[] = $new;
            $renames[(string) $field->key] = $new;

            DB::table('merge_fields')->where('id', $field->id)->update(['key' => $new, 'updated_at' => now()]);
        }

        return $renames;
    }

    private function needsRename(string $key, string $label): bool
    {
        $validFormat = strlen($key) <= MergeField::KEY_MAX_LENGTH
            && preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $key) === 1
            && ! in_array($key, MergeField::RESERVED_KEYS, true);

        if (! $validFormat) {
            return true;
        }

        // Valid characters, but produced by the old generator from an uppercase label
        // (e.g. "NÚMERO DE TELÉFONO" → n_u_m_e_r_o_d_e_t_e_l_e_f_o_n_o).
        $slug = MergeField::slugKey($label);
        $old = mb_substr(Str::snake(Str::ascii($label)), 0, MergeField::KEY_MAX_LENGTH);

        return $old !== '' && $old !== $slug
            && str_starts_with($key, $old)
            && ! str_starts_with($key, substr($slug, 0, 40));
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameReferences(int $organizationId, array $renames): void
    {
        foreach ($renames as $old => $new) {
            // Values keep their position under the new key; an existing value under the new key is replaced.
            DB::update(
                'UPDATE contacts SET fields = (fields - CAST(? AS text)) || jsonb_build_object(CAST(? AS text), fields -> CAST(? AS text)) WHERE organization_id = ? AND jsonb_exists(fields, ?)',
                [$old, $new, $old, $organizationId, $old],
            );
        }

        $this->renameInSegments($organizationId, $renames);
        $this->renameInAutomations($organizationId, $renames);
        $this->renameInContent($organizationId, $renames);
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameInSegments(int $organizationId, array $renames): void
    {
        if (! Schema::hasTable('segments')) {
            return;
        }

        foreach (DB::table('segments')->where('organization_id', $organizationId)->get(['id', 'conditions']) as $segment) {
            $conditions = $this->decode($segment->conditions);
            $updated = $this->renameConditions($conditions, $renames);

            if ($updated !== $conditions) {
                DB::table('segments')->where('id', $segment->id)->update(['conditions' => json_encode($updated)]);
            }
        }
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameInAutomations(int $organizationId, array $renames): void
    {
        if (! Schema::hasTable('automations')) {
            return;
        }

        foreach (DB::table('automations')->where('organization_id', $organizationId)->get(['id', 'trigger_config']) as $automation) {
            $config = $this->decode($automation->trigger_config);
            $key = $config['field_key'] ?? null;

            if (is_string($key) && isset($renames[$key])) {
                $config['field_key'] = $renames[$key];
                DB::table('automations')->where('id', $automation->id)->update(['trigger_config' => json_encode($config)]);
            }
        }

        $steps = DB::table('automation_steps')
            ->join('automations', 'automations.id', '=', 'automation_steps.automation_id')
            ->where('automations.organization_id', $organizationId)
            ->get(['automation_steps.id', 'automation_steps.config']);

        foreach ($steps as $step) {
            $config = $this->decode($step->config);

            if (! is_array($config['conditions'] ?? null)) {
                continue;
            }

            $conditions = $this->renameConditions($config['conditions'], $renames);

            if ($conditions !== $config['conditions']) {
                $config['conditions'] = $conditions;
                DB::table('automation_steps')->where('id', $step->id)->update(['config' => json_encode($config)]);
            }
        }
    }

    /**
     * `{{ old }}` → `{{ new }}` in unsent campaigns (and their A/B variants) and in templates.
     *
     * @param  array<string, string>  $renames
     */
    private function renameInContent(int $organizationId, array $renames): void
    {
        $campaignIds = DB::table('campaigns')
            ->where('organization_id', $organizationId)
            ->where('is_historic', false)
            ->where('status', '!=', 'sent')
            ->pluck('id');

        foreach ($campaignIds as $id) {
            $this->replaceInRow('campaigns', (int) $id, ['subject', 'preheader', 'mjml', 'html'], $renames);
        }

        if (Schema::hasTable('campaign_variants')) {
            foreach (DB::table('campaign_variants')->whereIn('campaign_id', $campaignIds)->pluck('id') as $id) {
                $this->replaceInRow('campaign_variants', (int) $id, ['subject', 'preheader', 'mjml', 'html'], $renames);
            }
        }

        foreach (DB::table('templates')->where('organization_id', $organizationId)->pluck('id') as $id) {
            $this->replaceInRow('templates', (int) $id, ['mjml', 'html'], $renames);
        }
    }

    /**
     * @param  list<string>  $columns  text columns; the jsonb `design` column is always included
     * @param  array<string, string>  $renames
     */
    private function replaceInRow(string $table, int $id, array $columns, array $renames): void
    {
        $row = DB::table($table)->where('id', $id)->select($columns)->selectRaw('design::text AS design')->first();

        if ($row === null) {
            return;
        }

        $changes = [];

        foreach ([...$columns, 'design'] as $column) {
            $value = $row->{$column};

            if (! is_string($value) || ! str_contains($value, '{{')) {
                continue;
            }

            $replaced = $this->replaceVariables($value, $renames);

            if ($replaced !== $value) {
                $changes[$column] = $replaced;
            }
        }

        if ($changes !== []) {
            DB::table($table)->where('id', $id)->update($changes);
        }
    }

    /**
     * Matches `{{ key }}` and `{{ key | "text" }}` (spaces optional), keeping everything but the key.
     *
     * @param  array<string, string>  $renames
     */
    private function replaceVariables(string $text, array $renames): string
    {
        foreach ($renames as $old => $new) {
            $text = (string) preg_replace_callback(
                '/(\{\{\s*)'.preg_quote($old, '/').'(?=\s*(?:\||\}\}))/u',
                fn (array $m): string => $m[1].$new,
                $text,
            );
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $conditions
     * @param  array<string, string>  $renames
     * @return array<mixed>
     */
    private function renameConditions(array $conditions, array $renames): array
    {
        foreach ($conditions as $i => $condition) {
            $field = is_array($condition) ? ($condition['field'] ?? null) : null;

            if (is_string($field) && str_starts_with($field, 'field:') && isset($renames[substr($field, 6)])) {
                $conditions[$i]['field'] = 'field:'.$renames[substr($field, 6)];
            }
        }

        return $conditions;
    }

    /**
     * @return array<mixed>
     */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
