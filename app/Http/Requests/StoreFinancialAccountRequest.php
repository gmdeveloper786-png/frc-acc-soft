<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('financial_accounts.manage');
    }

    public function rules(): array
    {
        $id = ($this->route('financialAccount') ?? $this->route('financial_account'))?->id;

        return [
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'name' => ['required', 'string', 'max:255'],
            'account_id' => [
                'required', 'integer',
                // An organisational ledger may back one account per branch; branch balances come from the journal's branch.
                Rule::unique('financial_accounts')->where(fn ($query) => $query->where('branch_id', $this->integer('branch_id')))->ignore($id),
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('level', 'ledger')->where('account_type', 'asset')->where('is_postable', true)->where('is_active', true)),
                $this->sameTypeOnLedger($id),
            ],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereIn('status', ['publish', 'active'])],
            'bank_name' => ['nullable', 'required_if:type,bank', 'string', 'max:255'],
            'account_title' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3'],
            'opening_reference' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.unique' => 'This branch already has a cash or bank account on this ledger. Each branch can link a ledger once, so its balance stays reconcilable.',
        ];
    }

    /** Cash and bank accounts never share a ledger, so every record on a ledger is of the same type. */
    private function sameTypeOnLedger(?int $id): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($id): void {
            $other = FinancialAccount::query()->where('account_id', (int) $value)->when($id, fn ($query) => $query->whereKeyNot($id))
                ->where('type', '!=', (string) $this->input('type'))->value('type');
            if ($other !== null) {
                $fail("This ledger is already used by {$other} accounts. Cash and bank accounts cannot share a ledger.");
            }
        };
    }
}
