<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\MergeField;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Audiences (lists) and their subscriber counts.
 */
class AudienceController extends Controller
{
    /**
     * List audiences.
     */
    public function index(): JsonResponse
    {
        $counts = $this->counts();

        return response()->json([
            'data' => Audience::query()->orderBy('name')->get()->map(fn (Audience $a) => $this->present($a, $counts[$a->id] ?? [])),
            /** Custom fields that contacts can have (`fields` in contacts). */
            'fields' => MergeField::query()->orderBy('position')->get()->map(fn (MergeField $f) => [
                'key' => $f->key, 'label' => $f->label, 'type' => $f->type->value, 'required' => $f->required,
            ]),
        ]);
    }

    /**
     * Show one audience.
     */
    public function show(Audience $audience): JsonResponse
    {
        return response()->json(['data' => $this->present($audience, $this->counts()[$audience->id] ?? [])]);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function present(Audience $audience, array $counts): array
    {
        return [
            'id' => $audience->id,
            'name' => $audience->name,
            'double_opt_in' => $audience->double_opt_in,
            /** Members by status: subscribed, unsubscribed, cleaned, pending, non_subscribed. */
            'counts' => (object) $counts,
            'created_at' => $audience->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (DB::table('audience_contact')->selectRaw('audience_id, status, count(*) as total')->groupBy('audience_id', 'status')->get() as $row) {
            $counts[(int) $row->audience_id][(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }
}
