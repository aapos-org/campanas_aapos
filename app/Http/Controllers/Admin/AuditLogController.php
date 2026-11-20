<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $timezone = (string) config('app.display_timezone');

        $logs = AuditLog::query()
            ->with('causer')
            ->when($validated['event'] ?? null, fn ($q, $event) => $q->where('event', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $event).'%'))
            ->when($validated['user'] ?? null, fn ($q, $userId) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $userId))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, $timezone)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'causer' => $log->causer instanceof User ? ['id' => $log->causer->id, 'name' => $log->causer->name] : null,
                'subject_type' => $log->subject_type !== null ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'properties' => $log->properties,
                'changes' => $log->attribute_changes,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/audit/Index', [
            'logs' => $logs,
            'filters' => [
                'event' => $validated['event'] ?? '',
                'user' => isset($validated['user']) ? (int) $validated['user'] : null,
                'from' => $validated['from'] ?? '',
                'to' => $validated['to'] ?? '',
            ],
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'events' => AuditLog::query()->select('event')->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
