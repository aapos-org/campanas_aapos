<?php

namespace App\Http\Controllers\Segments;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Segments\SegmentQuery;
use App\Services\Segments\SegmentRules;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SegmentController extends Controller
{
    public function __construct(
        private readonly SegmentRules $rules,
        private readonly SegmentQuery $query,
    ) {}

    public function index(Request $request): Response
    {
        $audienceId = $request->integer('audience') ?: null;

        $segments = Segment::query()->with('audience:id,name')
            ->when($audienceId, fn ($q, $id) => $q->where('audience_id', $id))
            ->orderBy('name')->paginate(25)->withQueryString();

        return Inertia::render('segments/Index', [
            'segments' => $segments->through(fn (Segment $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'audience' => $s->audience?->name,
                'description' => $this->rules->describe($s->match, $s->conditions),
                'subscribed' => (clone $this->query->forSegment($s))->where('ac.status', ContactStatus::Subscribed->value)->count(),
                'from_mailchimp' => $s->mailchimp_id !== null,
            ]),
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['audience' => $audienceId],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request->integer('audience') ?: null);
    }

    public function edit(Segment $segment): Response
    {
        return $this->form($segment, $segment->audience_id);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $segment = Segment::query()->create($this->validated($request, $user) + ['created_by' => $user->id]);
        Audit::record('segments.created', 'Segmento creado', $segment, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Segmento creado.']);

        return to_route('segments.index', ['audience' => $segment->audience_id]);
    }

    public function update(Request $request, Segment $segment, #[CurrentUser] User $user): RedirectResponse
    {
        $segment->update($this->validated($request, $user));
        Audit::record('segments.updated', 'Segmento modificado', $segment, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Segmento guardado.']);

        return to_route('segments.index', ['audience' => $segment->audience_id]);
    }

    public function destroy(Segment $segment, #[CurrentUser] User $user): RedirectResponse
    {
        $inUse = Campaign::query()->where('segment_id', $segment->id)
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value])->count();

        if ($inUse > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "No se puede eliminar: lo usan {$inUse} campañas sin enviar."]);

            return back();
        }

        $segment->delete();
        Audit::record('segments.deleted', 'Segmento eliminado', $segment, [], $user);

        return to_route('segments.index');
    }

    /**
     * Live count while building the segment (no save).
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience_id' => ['required', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $request->user()?->organization_id)->whereNull('deleted_at')],
            'match' => ['required', Rule::in(['all', 'any'])],
        ]);
        $conditions = $this->rules->validate($request->input('conditions'));

        $query = $this->query->base((int) $data['audience_id']);
        $this->query->apply($query, $data['match'], $conditions);

        return response()->json([
            'total' => (clone $query)->count(),
            'subscribed' => (clone $query)->where('ac.status', ContactStatus::Subscribed->value)->count(),
            'sample' => (clone $query)->orderBy('c.email')->limit(10)->get(['c.id', 'c.email', 'c.first_name', 'c.last_name', 'ac.status'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'email' => (string) $r->email,
                    'name' => trim($r->first_name.' '.$r->last_name),
                    'status' => ContactStatus::from((string) $r->status)->label(),
                ]),
        ]);
    }

    private function form(?Segment $segment, ?int $audienceId): Response
    {
        return Inertia::render('segments/Edit', [
            'segment' => $segment ? [
                'id' => $segment->id,
                'name' => $segment->name,
                'audience_id' => $segment->audience_id,
                'match' => $segment->match,
                'conditions' => $segment->conditions,
            ] : null,
            'defaultAudience' => $audienceId,
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
            'catalog' => $this->rules->catalog(),
            'operators' => SegmentRules::OPERATORS,
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'campaigns' => Campaign::query()->where('kind', 'regular')
                ->whereIn('status', [CampaignStatus::Sent->value, CampaignStatus::Sending->value, CampaignStatus::Testing->value])
                ->orderByDesc('finished_at')->orderByDesc('id')->limit(300)->get(['id', 'name', 'finished_at', 'is_historic'])
                ->map(fn (Campaign $c) => ['id' => $c->id, 'name' => $c->name, 'historic' => $c->is_historic, 'date' => $c->finished_at?->toDateString()]),
        ]);
    }

    /**
     * @return array{audience_id: int, name: string, match: string, conditions: list<array{field: string, op: string, value: string|int|null}>}
     */
    private function validated(Request $request, User $user): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'audience_id' => ['required', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'match' => ['required', Rule::in(['all', 'any'])],
        ], [], ['name' => 'nombre', 'audience_id' => 'audiencia']);

        return [
            'name' => (string) $data['name'],
            'audience_id' => (int) $data['audience_id'],
            'match' => (string) $data['match'],
            'conditions' => $this->rules->validate($request->input('conditions')),
        ];
    }
}
