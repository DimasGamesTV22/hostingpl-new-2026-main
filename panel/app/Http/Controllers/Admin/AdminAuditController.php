<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logs = AuditLog::with('user:id,name,email')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->query('user'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->query('subject_type'), fn ($q, $t) => $q->where('subject_type', $t))
            ->when($request->query('ip'), fn ($q, $ip) => $q->where('ip', 'like', $ip.'%'))
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'user', 'subject_type', 'ip', 'from', 'to']),
            'actions' => AuditLog::distinct()->orderBy('action')->pluck('action')->take(100)->all(),
            'subjects' => AuditLog::whereNotNull('subject_type')->distinct()->pluck('subject_type')->all(),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        return view('admin.audit-show', compact('auditLog'));
    }
}
