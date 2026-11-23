<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Services\Reports\OverviewReport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, OverviewReport $report, CurrentOrganization $current): Response
    {
        $audiences = Audience::query()->orderBy('name')->get(['id', 'name']);
        $audienceId = $request->validate([
            'audience' => ['nullable', 'integer', Rule::in($audiences->pluck('id'))],
        ])['audience'] ?? null;
        $audienceId = $audienceId !== null ? (int) $audienceId : null;
        $timezone = $current->get()->timezone ?? (string) config('app.display_timezone', 'America/Guatemala');

        return Inertia::render('reports/Index', [
            'growth' => $report->growth($timezone, $audienceId),
            'totals' => $report->totals($audienceId),
            'statusLabels' => collect(ContactStatus::cases())->mapWithKeys(fn (ContactStatus $s) => [$s->value => $s->label()]),
            'campaigns' => $report->campaigns($audienceId),
            'audiences' => $audiences,
            'filters' => ['audience' => $audienceId],
        ]);
    }
}
