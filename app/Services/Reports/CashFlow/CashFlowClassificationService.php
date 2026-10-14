<?php

namespace App\Services\Reports\CashFlow;

use App\Enums\AccountLevel;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\CashFlowClassification;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintains the explicit ledger → cash-flow activity configuration. A ledger
 * is only ever classified by a user; nothing is guessed from names or types.
 */
class CashFlowClassificationService
{
    public function __construct(private CashMovementReader $reader, private AuditLogger $audit) {}

    /**
     * Ledgers that may carry a classification: active, postable and not a
     * cash, bank or petty cash ledger.
     *
     * @return Builder<Account>
     */
    public function classifiable(): Builder
    {
        return Account::query()->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)
            ->whereNotIn('id', $this->reader->cashLedgerIds());
    }

    /** Set the activity of a ledger, or clear it when the activity is null. */
    public function set(Account $account, ?CashFlowActivity $activity, User $actor): ?CashFlowClassification
    {
        abort_unless($actor->is_active && $actor->can('cash_flow.manage_classification'), 403);

        return DB::transaction(function () use ($account, $activity, $actor): ?CashFlowClassification {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            $existing = CashFlowClassification::query()->where('account_id', $account->id)->lockForUpdate()->first();
            $previous = $existing?->activity?->value;

            if ($activity === null) {
                if ($existing) {
                    $existing->delete();
                    $this->audit->log('cash_flow.classification_cleared', $account, ['account_code' => $account->code, 'previous_activity' => $previous]);
                }

                return null;
            }

            if (in_array((int) $account->id, $this->reader->cashLedgerIds(), true)) {
                throw ValidationException::withMessages(['activity' => 'A cash, bank or petty cash ledger cannot be classified: it is the cash position itself.']);
            }
            if ($account->level !== AccountLevel::Ledger || ! $account->is_postable || ! $account->is_active) {
                throw ValidationException::withMessages(['activity' => 'Only an active postable ledger account can be classified.']);
            }
            if ($previous === $activity->value) {
                return $existing;
            }

            $classification = CashFlowClassification::query()->updateOrCreate(['account_id' => $account->id], ['activity' => $activity, 'updated_by' => $actor->id]);
            $this->audit->log('cash_flow.classification_set', $account, ['account_code' => $account->code, 'previous_activity' => $previous, 'activity' => $activity->value]);

            return $classification;
        });
    }
}
