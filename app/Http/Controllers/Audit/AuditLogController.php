<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Audit\AuditLogViewer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only audit trail. Browsing is deliberately not audited itself, and
 * there are no create, update or delete actions.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogViewer $viewer, AuditLogPresenter $presenter): View
    {
        abort_unless($request->user()->can('audit_logs.view'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'actor_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:191'],
            'record_type' => ['nullable', 'string', 'max:191'],
            'record_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'result' => ['nullable', Rule::in(['refused', 'recorded'])],
            'search' => ['nullable', 'string', 'max:100'],
            'quick' => ['nullable', Rule::in(array_keys(AuditLogViewer::QUICK_FILTERS))],
        ]);

        return view('audit.index', [
            'logs' => $viewer->filtered($request->user(), $filters)->with(['actor:id,name', 'branch:id,name'])->orderByDesc('audit_logs.id')->paginate(AuditLogViewer::PER_PAGE)->withQueryString(),
            'options' => $viewer->options($request->user()),
            'quickFilters' => AuditLogViewer::QUICK_FILTERS,
            'presenter' => $presenter,
        ]);
    }

    public function show(Request $request, AuditLog $auditLog, AuditLogViewer $viewer, AuditLogPresenter $presenter): View
    {
        abort_unless($request->user()->can('audit_logs.view') && $viewer->canView($request->user(), $auditLog), 403);
        $auditLog->load(['actor:id,name,email', 'branch:id,name']);

        return view('audit.show', [
            'log' => $auditLog,
            'presenter' => $presenter,
            'properties' => $presenter->properties($auditLog, $request->user()),
            'salaryMasked' => $presenter->isPayrollSensitive($auditLog) && ! $request->user()->can('payroll.view_sensitive'),
        ]);
    }
}
