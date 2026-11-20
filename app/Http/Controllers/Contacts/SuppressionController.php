<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\SuppressionReason;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Contacts\SuppressionService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SuppressionController extends Controller
{
    public function __construct(private readonly SuppressionService $service) {}

    public function index(Request $request): Response
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $reason = SuppressionReason::tryFrom((string) $request->query('reason', ''));

        $suppressions = Suppression::query()
            ->with('audience:id,name')
            ->when($search !== '', fn ($q) => $q->where('email', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%'))
            ->when($reason, fn ($q) => $q->where('reason', $reason))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Suppression $s) => [
                'id' => $s->id,
                'email' => $s->email,
                'scope' => $s->audience->name ?? 'Todas las audiencias',
                'is_global' => $s->isGlobal(),
                'reason' => $s->reason->value,
                'reason_label' => $s->reason->label(),
                'source' => $s->source,
                'removable' => $s->reason->isRemovable(),
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return Inertia::render('suppressions/Index', [
            'suppressions' => $suppressions,
            'filters' => ['search' => $search, 'reason' => $reason->value ?? ''],
            'reasons' => array_map(fn (SuppressionReason $r) => ['value' => $r->value, 'label' => $r->label()], SuppressionReason::cases()),
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'emails' => ['required', 'string', 'max:100000'],
            'audience_id' => ['nullable', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $user->organization_id)],
            'detail' => ['nullable', 'string', 'max:500'],
        ], [], ['emails' => 'correos', 'audience_id' => 'audiencia']);

        $emails = collect(preg_split('/[\s,;]+/', mb_strtolower($data['emails'])) ?: [])
            ->map(fn ($e) => trim((string) $e))->filter()->unique()->values();

        $invalid = $emails->reject(fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false);
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages(['emails' => 'Correos no válidos: '.$invalid->take(5)->implode(', ').($invalid->count() > 5 ? '…' : '')]);
        }

        $audience = isset($data['audience_id']) ? Audience::query()->find((int) $data['audience_id']) : null;

        foreach ($emails as $email) {
            $this->service->suppress($email, SuppressionReason::Manual, $audience, 'manual', $data['detail'] ?? null, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$emails->count()} direcciones agregadas a la lista de supresión."]);

        return back();
    }

    public function destroy(Suppression $suppression, #[CurrentUser] User $user): RedirectResponse
    {
        try {
            $this->service->lift($suppression, $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['suppression' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Supresión eliminada. La persona deberá volver a suscribirse.']);

        return back();
    }
}
