<?php

namespace App\Services\Automations;

use App\Enums\AutomationStepType;
use App\Enums\AutomationTrigger;
use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Enums\FieldType;
use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\Tag;
use App\Services\Segments\SegmentRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates and saves an automation's trigger and steps. Every "send email" step owns a
 * backing campaign (kind = automation) that stores its content and collects its report.
 */
class AutomationEditor
{
    public const int MAX_STEPS = 30;

    public function __construct(private readonly SegmentRules $segmentRules) {}

    /**
     * @return array<string, int|string>
     */
    public function triggerConfig(mixed $type, mixed $config): array
    {
        $trigger = AutomationTrigger::tryFrom((string) $type)
            ?? throw ValidationException::withMessages(['trigger_type' => 'Elige un disparador válido.']);
        $config = is_array($config) ? $config : [];

        return match ($trigger) {
            AutomationTrigger::Subscribed => [],
            AutomationTrigger::TagAdded => ['tag_id' => Tag::query()->whereKey((int) ($config['tag_id'] ?? 0))->exists()
                ? (int) $config['tag_id']
                : throw ValidationException::withMessages(['trigger_config.tag_id' => 'Elige la etiqueta que inicia el flujo.'])],
            AutomationTrigger::DateField => $this->dateConfig($config),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, int|string>
     */
    private function dateConfig(array $config): array
    {
        $key = (string) ($config['field_key'] ?? '');
        $valid = MergeField::query()->where('key', $key)->whereIn('type', [FieldType::Date->value, FieldType::Birthday->value])->exists();

        if (! $valid) {
            throw ValidationException::withMessages(['trigger_config.field_key' => 'Elige un campo de tipo fecha o cumpleaños.']);
        }

        $days = (int) ($config['days_before'] ?? 0);
        $hour = (int) ($config['send_hour'] ?? 9);

        if ($days < 0 || $days > 60) {
            throw ValidationException::withMessages(['trigger_config.days_before' => 'Los días de anticipación deben estar entre 0 y 60.']);
        }

        if ($hour < 0 || $hour > 23) {
            throw ValidationException::withMessages(['trigger_config.send_hour' => 'La hora debe estar entre 0 y 23.']);
        }

        return ['field_key' => $key, 'days_before' => $days, 'send_hour' => $hour];
    }

    /**
     * Validates the submitted steps.
     *
     * @return list<array{id: int|null, type: AutomationStepType, config: array<string, mixed>}>
     */
    public function validateSteps(mixed $steps): array
    {
        if (! is_array($steps) || $steps === []) {
            throw ValidationException::withMessages(['steps' => 'Agrega al menos un paso.']);
        }

        if (count($steps) > self::MAX_STEPS) {
            throw ValidationException::withMessages(['steps' => 'Máximo '.self::MAX_STEPS.' pasos por automatización.']);
        }

        $result = [];

        foreach (array_values($steps) as $i => $step) {
            $n = $i + 1;
            $step = is_array($step) ? $step : [];
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $type = AutomationStepType::tryFrom((string) ($step['type'] ?? ''))
                ?? throw ValidationException::withMessages(['steps' => "Paso {$n}: tipo de paso no válido."]);
            $fail = fn (string $message) => throw ValidationException::withMessages(['steps' => "Paso {$n} ({$type->label()}): {$message}"]);

            $clean = match ($type) {
                AutomationStepType::SendEmail => [
                    'subject' => ($subject = trim((string) ($config['subject'] ?? ''))) !== '' && mb_strlen($subject) <= 200 ? $subject : $fail('escribe el asunto (máximo 200 caracteres).'),
                    'preheader' => mb_substr(trim((string) ($config['preheader'] ?? '')), 0, 200),
                ],
                AutomationStepType::Wait => [
                    'amount' => ($amount = (int) ($config['amount'] ?? 0)) >= 1 && $amount <= 365 ? $amount : $fail('indica una cantidad entre 1 y 365.'),
                    'unit' => in_array($config['unit'] ?? null, ['minutes', 'hours', 'days'], true) ? $config['unit'] : $fail('elige minutos, horas o días.'),
                ],
                AutomationStepType::AddTag, AutomationStepType::RemoveTag => [
                    'tag_id' => Tag::query()->whereKey((int) ($config['tag_id'] ?? 0))->exists() ? (int) $config['tag_id'] : $fail('elige una etiqueta.'),
                ],
                AutomationStepType::Condition => [
                    'match' => ($config['match'] ?? 'all') === 'any' ? 'any' : 'all',
                    'conditions' => $this->conditions($config['conditions'] ?? null, $n),
                    'on_false' => ($config['on_false'] ?? 'exit') === 'skip' ? 'skip' : 'exit',
                ],
            };

            $result[] = ['id' => isset($step['id']) ? (int) $step['id'] : null, 'type' => $type, 'config' => $clean];
        }

        return $result;
    }

    /**
     * @return list<array{field: string, op: string, value: string|int|null}>
     */
    private function conditions(mixed $conditions, int $n): array
    {
        try {
            return $this->segmentRules->validate($conditions);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['steps' => "Paso {$n} (Condición): ".collect($e->errors())->flatten()->first()]);
        }
    }

    /**
     * Replaces the steps, keeping the ids (and backing campaigns) of the ones that stay.
     *
     * @param  list<array{id: int|null, type: AutomationStepType, config: array<string, mixed>}>  $steps
     */
    public function saveSteps(Automation $automation, array $steps): void
    {
        DB::transaction(function () use ($automation, $steps) {
            $existing = $automation->steps()->get()->keyBy('id');
            $keep = [];

            foreach ($steps as $position => $data) {
                $step = $data['id'] !== null ? $existing->get($data['id']) : null;

                if ($step === null || $step->type !== $data['type']) {
                    $step = new AutomationStep(['automation_id' => $automation->id]);
                }

                $step->fill(['position' => $position + 1, 'type' => $data['type'], 'config' => $data['config']])->save();
                $keep[] = $step->id;

                if ($data['type'] === AutomationStepType::SendEmail) {
                    $this->syncCampaign($automation, $step, $position + 1);
                }
            }

            $automation->steps()->with('campaign')->whereNotIn('id', $keep)->get()->each(function (AutomationStep $step) {
                $campaign = $step->campaign;
                $step->delete();

                // Keep the backing campaign if someone already received it (its report stays).
                if ($campaign !== null && ! CampaignRecipient::query()->where('campaign_id', $campaign->id)->exists()) {
                    $campaign->forceDelete();
                }
            });
        });
    }

    /**
     * Mirrors the automation settings into a step's backing campaign.
     */
    public function syncCampaign(Automation $automation, AutomationStep $step, ?int $number = null): Campaign
    {
        $campaign = $step->loadMissing('campaign')->campaign ?? new Campaign([
            'organization_id' => $automation->organization_id,
            'kind' => 'automation',
            'editor' => 'visual',
            'created_by' => $automation->created_by,
        ]);

        $campaign->forceFill([
            'name' => mb_substr($automation->name.' · Correo '.($number ?? $step->position), 0, 255),
            'subject' => $step->config['subject'] ?? null,
            'preheader' => ($step->config['preheader'] ?? '') ?: null,
            'audience_id' => $automation->audience_id,
            'mail_connection_id' => $automation->mail_connection_id,
            'from_name' => $automation->from_name,
            'from_email' => $automation->from_email,
            'reply_to' => $automation->reply_to,
            'status' => CampaignStatus::Automation,
        ])->save();

        if ($step->campaign_id !== $campaign->id) {
            $step->forceFill(['campaign_id' => $campaign->id])->save();
        }

        return $campaign;
    }

    /**
     * Problems that prevent activating the automation (empty = ready).
     *
     * @return list<string>
     */
    public function activationProblems(Automation $automation): array
    {
        $problems = [];
        $steps = $automation->steps()->with('campaign')->get();
        $organization = Organization::query()->find($automation->organization_id);

        if ($steps->isEmpty()) {
            $problems[] = 'Agrega al menos un paso.';
        }

        $connection = $automation->loadMissing('mailConnection')->mailConnection;

        if ($steps->contains(fn (AutomationStep $s) => $s->type === AutomationStepType::SendEmail)) {
            if ($connection === null) {
                $problems[] = 'Elige una conexión de envío.';
            } elseif ($connection->status !== ConnectionStatus::Active) {
                $problems[] = "La conexión «{$connection->name}» no está verificada o tiene errores.";
            }

            if (blank($organization?->physical_address)) {
                $problems[] = 'Falta la dirección física de la organización (obligatoria en el pie de los correos).';
            }
        }

        foreach ($steps as $step) {
            if ($step->type === AutomationStepType::SendEmail && blank(strip_tags((string) $step->campaign?->html))) {
                $problems[] = "El correo del paso {$step->position} no tiene contenido.";
            }
        }

        return $problems;
    }
}
