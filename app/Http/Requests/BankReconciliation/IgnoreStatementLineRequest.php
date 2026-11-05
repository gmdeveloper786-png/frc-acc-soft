<?php

namespace App\Http\Requests\BankReconciliation;

use App\Models\BankReconciliation;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class IgnoreStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->can('bank_reconciliation.prepare')) {
            return false;
        }

        $reconciliation = $this->route('bankReconciliation');
        if ($reconciliation instanceof BankReconciliation) {
            $account = $reconciliation->financialAccount;
            if ($account) {
                $branchAccess = app(BranchAccess::class);

                return $account->type === 'bank' && $account->bookVisibleTo($user, $branchAccess->canAccess($user, $account->branch));
            }
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason') && is_string($this->input('reason'))) {
            $this->merge([
                'reason' => trim($this->input('reason')),
            ]);
        }
    }
}
