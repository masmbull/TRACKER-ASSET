<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * Immutable system-wide activity trail (PRD §40).
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('view-activity-log');

        $logs = ActivityLog::query()
            ->with('actor')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.trim((string) $request->input('search')).'%';
                $q->where(fn ($sub) => $sub
                    ->where('entity_identifier', 'like', $search)
                    ->orWhere('entity_type', 'like', $search)
                    ->orWhere('ip_address', 'like', $search)
                    ->orWhere('request_id', 'like', $search)
                    ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', $search)));
            })
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('activity.index', compact('logs', 'actions'));
    }
}
