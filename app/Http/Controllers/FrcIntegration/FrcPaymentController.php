<?php

namespace App\Http\Controllers\FrcIntegration;

use App\Http\Controllers\Controller;
use App\Models\FrcPayment;
use App\Models\FrcSyncException;
use App\Services\Frc\FinancialSync\FrcFinancialApi;
use App\Services\Frc\FinancialSync\FrcMirrorAccess;
use App\Services\Frc\FinancialSync\FrcPaymentListService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only screens of the mirrored FRC payments. There is no personal data in the mirror. */
class FrcPaymentController extends Controller
{
    use ResolvesFrcFilters;

    public function index(Request $request, FrcPaymentListService $list): View
    {
        return $this->listView($request, $list, false);
    }

    public function unmapped(Request $request, FrcPaymentListService $list): View
    {
        return $this->listView($request, $list, true);
    }

    public function show(Request $request, FrcPayment $payment, FrcMirrorAccess $access): View
    {
        abort_unless($access->canViewPayment($request->user(), $payment), 403);
        $payment->load(['enrollment', 'branch:id,name', 'service:id,name', 'postings.journalEntry:id,voucher_number,status']);
        $exceptions = FrcSyncException::query()
            ->where(fn ($query) => $query->where(['entity' => FrcFinancialApi::Payments, 'external_id' => $payment->frc_payment_id])
                ->orWhere(fn ($enrollment) => $enrollment->where(['entity' => FrcFinancialApi::Enrollments, 'external_id' => $payment->frc_enrollment_id])))
            ->latest('id')->limit(100)->get();

        return view('frc-integration.payments.show', ['payment' => $payment, 'exceptions' => $exceptions]);
    }

    private function listView(Request $request, FrcPaymentListService $list, bool $unmapped): View
    {
        $resolved = $this->frcFilters($request);
        $query = $list->filtered($request->user(), [...$resolved['filters'], 'unmapped' => $unmapped]);

        return view('frc-integration.payments.index', [
            ...$resolved,
            'unmapped' => $unmapped,
            'summary' => $list->summary($query),
            'payments' => (clone $query)->with(['branch:id,name', 'service:id,name'])
                ->orderByDesc('payment_date')->orderByDesc('frc_payment_id')
                ->paginate(FrcPaymentListService::PER_PAGE)->withQueryString(),
            'hasAnyPayment' => FrcPayment::query()->exists(),
        ]);
    }
}
