<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;

/**
 * The budget grid is submitted as one JSON document in `lines_json` instead of
 * one request variable per cell, so a full Chart of Accounts budget is not
 * limited by PHP's max_input_vars. The document always holds the complete set
 * of lines of the budget: saving replaces the stored set with it.
 */
class SaveBudgetRequest extends FormRequest
{
    /** Upper bound of lines in one budget (ledgers x periods). */
    public const MAX_LINES = 20000;

    /** Upper bound of the JSON document in bytes. */
    public const MAX_PAYLOAD_BYTES = 2_000_000;

    /** At most 15 integer digits and four decimals: the range of DECIMAL(19,4). */
    private const AMOUNT_PATTERN = '/^\d{1,15}(\.\d{1,4})?$/';

    private const LINE_KEYS = ['account_id', 'amount', 'period_id'];

    /** @var list<array{account_id: int, accounting_period_id: int, amount: string}> */
    private array $decodedLines = [];

    public function authorize(): bool
    {
        return $this->user()->can($this->route('budget') ? 'budgets.update' : 'budgets.create');
    }

    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'name' => ['required', 'string', 'max:150'],
            'lines_json' => ['required', 'string', 'max:'.self::MAX_PAYLOAD_BYTES],
        ];
    }

    public function messages(): array
    {
        return [
            'lines_json.required' => 'The budget lines were not received. The budget form needs JavaScript to send its lines; nothing was saved.',
            'lines_json.string' => 'The budget lines could not be read. Nothing was saved.',
            'lines_json.max' => 'The budget lines exceed the maximum size of one budget. Nothing was saved.',
        ];
    }

    public function attributes(): array
    {
        return ['lines_json' => 'budget lines'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('lines_json')) {
                return;
            }
            foreach ($this->decodeLines((string) $this->input('lines_json')) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    /**
     * @return array{fiscal_year_id: int, branch_id: int, service_id: ?int, name: string, lines: list<array{account_id: int, accounting_period_id: int, amount: string}>}
     */
    public function budgetData(): array
    {
        $data = $this->validated();

        return [
            'fiscal_year_id' => (int) $data['fiscal_year_id'], 'branch_id' => (int) $data['branch_id'],
            'service_id' => filled($data['service_id'] ?? null) ? (int) $data['service_id'] : null,
            'name' => $data['name'], 'lines' => $this->decodedLines,
        ];
    }

    /**
     * Decode and check the structure of the line document. Whether an account
     * is budgetable and a period belongs to the fiscal year is decided by the
     * budget service, which also recalculates the total.
     *
     * @return array<string, string> validation errors keyed by field
     */
    private function decodeLines(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 3, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return ['lines_json' => 'The budget lines are not valid JSON. Nothing was saved.'];
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return ['lines_json' => 'The budget lines must be a list of {account_id, period_id, amount} entries. Nothing was saved.'];
        }
        if (count($decoded) > self::MAX_LINES) {
            return ['lines_json' => 'A budget can hold at most '.number_format(self::MAX_LINES).' lines. Nothing was saved.'];
        }

        $lines = [];
        $seen = [];
        foreach ($decoded as $index => $entry) {
            $number = $index + 1;
            $keys = is_array($entry) ? array_keys($entry) : [];
            sort($keys);
            if ($keys !== self::LINE_KEYS) {
                return ["lines.{$index}" => "Budget line {$number} must have exactly an account_id, a period_id and an amount."];
            }
            $accountId = $this->positiveInteger($entry['account_id']);
            $periodId = $this->positiveInteger($entry['period_id']);
            if ($accountId === null) {
                return ["lines.{$index}.account_id" => "Budget line {$number} has an invalid account."];
            }
            if ($periodId === null) {
                return ["lines.{$index}.accounting_period_id" => "Budget line {$number} has an invalid period."];
            }
            $amount = is_int($entry['amount']) ? (string) $entry['amount'] : $entry['amount'];
            if (! is_string($amount) || preg_match(self::AMOUNT_PATTERN, $amount = trim($amount)) !== 1) {
                return ["lines.{$index}.amount" => "Budget line {$number}: enter a non-negative amount with at most four decimals, sent as decimal text."];
            }
            if (isset($seen[$accountId.':'.$periodId])) {
                return ["lines.{$index}" => "Budget line {$number} repeats an account and period. Each account can be budgeted only once per period."];
            }
            $seen[$accountId.':'.$periodId] = true;
            $lines[] = ['account_id' => $accountId, 'accounting_period_id' => $periodId, 'amount' => $amount];
        }
        $this->decodedLines = $lines;

        return [];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1 ? (int) $value : null;
    }
}
