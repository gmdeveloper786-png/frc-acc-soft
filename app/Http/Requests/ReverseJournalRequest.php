<?php

namespace App\Http\Requests;

use App\Services\JournalReversalService;
use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('journals.reverse') === true;
    }

    public function rules(): array
    {
        return [
            'accounting_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:'.JournalReversalService::MIN_REASON_LENGTH, 'max:2000'],
        ];
    }
}
