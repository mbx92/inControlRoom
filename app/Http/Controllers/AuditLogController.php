<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    use \App\Http\Controllers\Concerns\AppliesSiteScope;
    public function index(Request $request): Response
    {
        $query = AuditLog::with(['user', 'site'])
            ->orderByDesc('created_at');

        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', 'like', "%{$request->action}%");
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay());
        }

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'global');

        $logs = $query->paginate(25)->through(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'payload' => $log->payload,
            'ip_address' => $log->ip_address,
            'result' => $log->result,
            'error_message' => $log->error_message,
            'user_name' => $log->user?->name ?? 'System',
            'site_name' => $log->site?->name ?? 'Global',
            'site_code' => $log->site?->code,
            'created_at' => $log->created_at->format('Y-m-d H:i:s'),
            'created_at_human' => $log->created_at->diffForHumans(),
        ]);

        return Inertia::render('AuditLog/Index', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'user_id', 'site', 'from', 'to']),
            'sites' => $this->scopedSitesQuery()
                ->get(['id', 'name', 'code'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'code' => $site->code,
                ]),
        ]);
    }
}
