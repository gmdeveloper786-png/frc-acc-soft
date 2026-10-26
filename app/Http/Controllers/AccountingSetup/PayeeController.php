<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Payee Directory: recipients that are neither vendors nor employees.
 * Vendors and employees stay in their own masters and are never copied here.
 */
class PayeeController extends Controller
{
    private const AUDITED = ['payee_type_id', 'name', 'entity_kind', 'phone', 'email', 'address', 'reference', 'description', 'is_active'];

    public function index(Request $request): View
    {
        $payees = Payee::query()->with('payeeType')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', '%'.$request->string('search').'%')->orWhere('reference', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('payee_type_id'), fn ($query) => $query->where('payee_type_id', $request->integer('payee_type_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status') === 'active'))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('accounting-setup.payees.index', ['payees' => $payees, 'types' => $this->directoryTypes(false)]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('vendors.create'), 403);

        return view('accounting-setup.payees.form', ['payee' => new Payee(['is_active' => true, 'entity_kind' => 'organization']), 'types' => $this->directoryTypes(true)]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('vendors.create'), 403);
        $payee = Payee::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);
        $audit->log('payees.created', $payee, $payee->only(self::AUDITED));

        return redirect()->route('payees.index')->with('status', 'Payee created.');
    }

    public function edit(Request $request, Payee $payee): View
    {
        abort_unless($request->user()->can('vendors.update'), 403);

        return view('accounting-setup.payees.form', ['payee' => $payee, 'types' => $this->directoryTypes(true, $payee->payee_type_id), 'locked' => $payee->isReferenced()]);
    }

    public function update(Request $request, Payee $payee, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('vendors.update'), 403);
        $data = $this->validated($request, $payee);
        if ($payee->isReferenced() && (int) $data['payee_type_id'] !== (int) $payee->payee_type_id) {
            throw ValidationException::withMessages(['payee_type_id' => 'Payments already name this payee, so its payee type cannot change.']);
        }
        $before = $payee->only(self::AUDITED);
        $payee->update($data);
        $audit->log('payees.updated', $payee, ['before' => $before, 'after' => $payee->fresh()->only(self::AUDITED)]);

        return redirect()->route('payees.index')->with('status', 'Payee updated.');
    }

    public function toggle(Request $request, Payee $payee, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('vendors.deactivate'), 403);
        $payee->update(['is_active' => ! $payee->is_active]);
        $audit->log('payees.status_changed', $payee, ['is_active' => $payee->is_active]);

        return back()->with('status', 'Payee status updated.');
    }

    /** Payee types whose source is the directory; only these may hold directory payees. */
    private function directoryTypes(bool $activeOnly, ?int $keep = null)
    {
        return PayeeType::query()->where('source_kind', PayeeType::SOURCE_DIRECTORY)
            ->when($activeOnly, fn ($query) => $query->where(fn ($inner) => $inner->where('is_active', true)->when($keep, fn ($own) => $own->orWhere('id', $keep))))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Payee $payee = null): array
    {
        $data = $request->validate([
            'payee_type_id' => ['required', 'integer', Rule::exists('payee_types', 'id')->where('source_kind', PayeeType::SOURCE_DIRECTORY)->where(fn ($query) => $query->where('is_active', true)->when($payee, fn ($own) => $own->orWhere('id', $payee->payee_type_id)))],
            'name' => ['required', 'string', 'max:255'],
            'entity_kind' => ['required', Rule::in(array_keys(Payee::ENTITY_KINDS))],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['payee_type_id.exists' => 'Select an active payee type that uses the Payee Directory.']);

        return [...$data, 'is_active' => $request->boolean('is_active')];
    }
}
