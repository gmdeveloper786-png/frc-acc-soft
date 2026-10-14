<?php

namespace App\Http\Controllers\FrcIntegration;

use App\Http\Controllers\Controller;
use App\Models\FrcSyncException;
use App\Models\FrcSyncRun;
use App\Services\AuditLogger;
use App\Services\Frc\FinancialSync\FrcFinancialApi;
use App\Services\Frc\FinancialSync\FrcFinancialSyncService;
use App\Services\Frc\FinancialSync\FrcMirrorAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Sync history (counters only, no record data) and sync exceptions. */
class FrcSyncHistoryController extends Controller
{
    public function runs(Request $request): View
    {
        $filters = $request->validate([
            'entity' => ['nullable', Rule::in(FrcFinancialApi::ENTITIES)],
            'status' => ['nullable', Rule::in([FrcFinancialSyncService::StatusRunning, FrcFinancialSyncService::StatusCompleted, FrcFinancialSyncService::StatusFailed])],
        ]);

        $runs = FrcSyncRun::query()->with('trigger:id,name')
            ->when($filters['entity'] ?? null, fn ($query, string $entity) => $query->where('entity', $entity))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('frc-integration.runs', ['runs' => $runs]);
    }

    public function exceptions(Request $request, FrcMirrorAccess $access): View
    {
        $filters = $request->validate([
            'code' => ['nullable', Rule::in(FrcFinancialSyncService::EXCEPTION_CODES)],
            'entity' => ['nullable', Rule::in(FrcFinancialApi::ENTITIES)],
            'state' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
        ]);
        $state = $filters['state'] ?? 'open';

        $exceptions = $access->exceptions($request->user())->with(['run:id,entity,mode', 'resolver:id,name'])
            ->when($filters['code'] ?? null, fn ($query, string $code) => $query->where('code', $code))
            ->when($filters['entity'] ?? null, fn ($query, string $entity) => $query->where('entity', $entity))
            ->when($state === 'open', fn ($query) => $query->whereNull('resolved_at'))
            ->when($state === 'resolved', fn ($query) => $query->whereNotNull('resolved_at'))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('frc-integration.exceptions', ['exceptions' => $exceptions, 'state' => $state]);
    }

    public function resolve(Request $request, FrcSyncException $exception, FrcMirrorAccess $access, AuditLogger $audit): RedirectResponse
    {
        abort_unless($access->canViewException($request->user(), $exception), 403);
        if ($exception->resolved_at !== null) {
            return back()->with('error', 'This exception was already resolved.');
        }

        $exception->forceFill(['resolved_at' => now(), 'resolved_by' => $request->user()->getKey()])->save();
        $audit->log('frc_sync.exception_resolved', $exception, ['code' => $exception->code, 'entity' => $exception->entity, 'external_id' => $exception->external_id]);

        return back()->with('status', 'Exception marked as resolved.');
    }
}
