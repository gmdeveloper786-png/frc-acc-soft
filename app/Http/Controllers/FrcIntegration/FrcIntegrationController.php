<?php

namespace App\Http\Controllers\FrcIntegration;

use App\Http\Controllers\Controller;
use App\Models\FrcSyncRun;
use App\Services\Frc\FinancialSync\FrcFinancialApi;
use App\Services\Frc\FinancialSync\FrcFinancialSyncService;
use App\Services\Frc\FinancialSync\FrcMirrorAccess;
use App\Services\Frc\FrcApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** FRC Financial Integration overview, manual sync and connection check. */
class FrcIntegrationController extends Controller
{
    public function index(Request $request, FrcMirrorAccess $access, FrcFinancialApi $api): View
    {
        $user = $request->user();
        $entities = [];
        foreach (FrcFinancialApi::ENTITIES as $entity) {
            $entities[$entity] = [
                'endpoint' => $api->endpoint($entity),
                'last_success' => FrcSyncRun::query()->where('entity', $entity)->where('status', FrcFinancialSyncService::StatusCompleted)->latest('finished_at')->latest('id')->first(),
                'last_run' => FrcSyncRun::query()->where('entity', $entity)->latest('started_at')->latest('id')->first(),
            ];
        }
        $awaitingApproval = collect($entities)->contains(fn (array $entity): bool => $entity['last_run']?->status === FrcFinancialSyncService::StatusFailed
            && str_contains((string) $entity['last_run']->error, 'awaiting approval'));

        $payments = $access->payments($user);
        $counts = [
            'enrollments' => $access->enrollments($user)->count(),
            'payments' => (clone $payments)->count(),
            'unmapped' => (clone $payments)->where(fn ($query) => $query->whereNull('branch_id')->orWhereNull('service_id'))->count(),
            'needs_review' => (clone $payments)->where('posting_status', FrcFinancialSyncService::PostingNeedsReview)->count(),
            'pending_verification' => (clone $payments)->where('source_status', 'pending_verification')->count(),
            'open_exceptions' => $access->exceptions($user)->whereNull('resolved_at')->count(),
        ];

        return view('frc-integration.index', [
            'entities' => $entities,
            'counts' => $counts,
            'awaitingApproval' => $awaitingApproval,
            'configuration' => [
                'enabled' => (bool) config('services.frc.enabled'),
                'base_url' => filled(config('services.frc.base_url')),
                'token' => filled(config('services.frc.token')),
            ],
            'neverSynced' => FrcSyncRun::query()->doesntExist(),
        ]);
    }

    public function sync(Request $request, FrcFinancialSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in([FrcFinancialSyncService::ModeFull, FrcFinancialSyncService::ModeIncremental])],
            'confirm' => ['accepted'],
        ]);

        $outcome = $sync->sync($validated['mode'], $request->user());

        return redirect()->route('frc-integration.index')->with($outcome->successful ? 'status' : 'error', $outcome->message);
    }

    public function check(FrcApiClient $client): RedirectResponse
    {
        $result = $client->connectionCheck();

        return back()->with($result->successful ? 'status' : 'error', $result->message);
    }
}
