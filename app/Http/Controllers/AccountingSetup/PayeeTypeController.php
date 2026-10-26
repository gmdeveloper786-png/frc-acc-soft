<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Models\PayeeType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Configurable payee categories. Each one picks one system source; a used type keeps its code and source. */
class PayeeTypeController extends Controller
{
    private const AUDITED = ['name', 'code', 'source_kind', 'description', 'is_active', 'sort_order'];

    public function index(): View
    {
        return view('accounting-setup.payee-types.index', ['types' => PayeeType::query()->withCount('payees')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);

        return view('accounting-setup.payee-types.form', ['type' => new PayeeType(['is_active' => true, 'source_kind' => PayeeType::SOURCE_DIRECTORY])]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);
        $type = PayeeType::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);
        $audit->log('payee_types.created', $type, $type->only(self::AUDITED));

        return redirect()->route('payee-types.index')->with('status', 'Payee type created.');
    }

    public function edit(Request $request, PayeeType $payeeType): View
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);

        return view('accounting-setup.payee-types.form', ['type' => $payeeType, 'locked' => $payeeType->isReferenced()]);
    }

    public function update(Request $request, PayeeType $payeeType, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);
        $data = $this->validated($request, $payeeType);
        if ($payeeType->isReferenced() && ($data['code'] !== $payeeType->code || $data['source_kind'] !== $payeeType->source_kind)) {
            throw ValidationException::withMessages(['source_kind' => 'Payees or payments already use this type, so its code and source cannot change. Create a new type instead.']);
        }
        $before = $payeeType->only(self::AUDITED);
        $payeeType->update($data);
        $audit->log('payee_types.updated', $payeeType, ['before' => $before, 'after' => $payeeType->fresh()->only(self::AUDITED)]);

        return redirect()->route('payee-types.index')->with('status', 'Payee type updated.');
    }

    public function toggle(Request $request, PayeeType $payeeType, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('account_mappings.manage'), 403);
        $payeeType->update(['is_active' => ! $payeeType->is_active]);
        $audit->log('payee_types.status_changed', $payeeType, ['is_active' => $payeeType->is_active]);

        return back()->with('status', 'Payee type status updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PayeeType $type = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('payee_types', 'code')->ignore($type?->id)],
            'source_kind' => ['required', Rule::in(array_keys(PayeeType::SOURCES))],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use lower-case letters, digits and underscores only.']);

        return [...$data, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active')];
    }
}
