<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Services\Reports\CampaignReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Campaigns (read only) and their statistics.
 */
class CampaignController extends Controller
{
    /**
     * List campaigns, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            /** Include campaigns migrated from Mailchimp. */
            'historic' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return CampaignResource::collection(Campaign::query()->where('kind', 'regular')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->boolean('historic'), fn ($q) => $q->where('is_historic', false))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString());
    }

    /**
     * Show a campaign with its statistics (sent, delivered, opens, clicks, bounces,
     * complaints and unsubscribes).
     */
    public function show(Campaign $campaign, CampaignReport $report): CampaignResource
    {
        abort_if($campaign->kind !== 'regular', 404);

        $resource = new CampaignResource($campaign);
        $resource->stats = $campaign->is_historic
            ? (array) $campaign->report_summary
            : $report->metrics($campaign);

        return $resource;
    }
}
