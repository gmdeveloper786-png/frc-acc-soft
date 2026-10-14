<?php

namespace App\Services\Frc\FinancialSync;

use App\Services\AccountingDecimal;
use Illuminate\Support\Carbon;

/**
 * Validates one FRC record and maps the whitelisted source fields to mirror
 * columns. Every other key the source sends (names, phones, notes, slips...)
 * is dropped here, so it can never reach the mirror. Failures throw an
 * InvalidFrcRecord whose message never contains source values.
 */
class FrcRecordNormalizer
{
    public const array PAYMENT_STATUSES = ['pending_verification', 'paid', 'rejected', 'cancelled', 'refunded'];

    public const array PAYMENT_METHODS = ['cash', 'bank_transfer', 'easypaisa', 'jazzcash', 'card', 'other'];

    /** Enrollment statuses the Finance Report counts towards Total Paid. */
    public const array ELIGIBLE_ENROLLMENT_STATUSES = ['approved', 'active', 'completed'];

    /** Source fields that make up the content hash, in a fixed order. */
    public const array ENROLLMENT_HASH_FIELDS = ['frc_enrollment_id', 'frc_branch_id', 'frc_service_id', 'source_status', 'payment_status', 'final_total', 'discount_amount', 'paid_amount', 'remaining_amount', 'source_approved_at', 'source_created_at', 'source_updated_at', 'source_deleted_at'];

    public const array PAYMENT_HASH_FIELDS = ['frc_payment_id', 'receipt_number', 'frc_enrollment_id', 'frc_branch_id', 'frc_service_id', 'amount', 'payment_method', 'source_status', 'enrollment_status', 'payment_date', 'verified_at', 'source_created_at', 'source_updated_at', 'source_deleted_at'];

    /** @return array<string, mixed> */
    public function enrollment(mixed $record): array
    {
        $record = $this->record($record, 'Enrollment');
        $id = $this->id($record, 'id', 'Enrollment', true);
        $label = 'Enrollment '.$id;

        return $this->withHash([
            'frc_enrollment_id' => $id,
            'frc_branch_id' => $this->id($record, 'branch_id', $label),
            'frc_service_id' => $this->id($record, 'service_id', $label),
            'source_status' => $this->code($record, 'status', $label, true),
            'payment_status' => $this->code($record, 'payment_status', $label),
            'final_total' => $this->amount($record, 'final_total', $label, false),
            'discount_amount' => $this->amount($record, 'discount_amount', $label, false),
            'paid_amount' => $this->amount($record, 'paid_amount', $label, false),
            'remaining_amount' => $this->amount($record, 'remaining_amount', $label, false, allowNegative: true),
            'source_approved_at' => $this->timestamp($record, 'approved_at', $label),
            'source_created_at' => $this->timestamp($record, 'created_at', $label),
            'source_updated_at' => $this->timestamp($record, 'updated_at', $label, true),
            'source_deleted_at' => $this->timestamp($record, 'deleted_at', $label),
        ], self::ENROLLMENT_HASH_FIELDS);
    }

    /** @return array<string, mixed> */
    public function payment(mixed $record): array
    {
        $record = $this->record($record, 'Payment');
        $id = $this->id($record, 'id', 'Payment', true);
        $label = 'Payment '.$id;
        $enrollmentId = $this->id($record, 'enrollment_id', $label, true);

        $enrollment = $record['enrollment'] ?? null;
        if ($enrollment !== null && ! is_array($enrollment)) {
            throw new InvalidFrcRecord($label.': the nested enrollment must be an object.');
        }
        if (is_array($enrollment) && isset($enrollment['id']) && (string) $enrollment['id'] !== (string) $enrollmentId) {
            throw new InvalidFrcRecord($label.': the nested enrollment id does not match enrollment_id.');
        }

        $method = $this->code($record, 'payment_method', $label, true);
        if (! in_array($method, self::PAYMENT_METHODS, true)) {
            throw new InvalidFrcRecord($label.': unknown payment method.');
        }
        $status = $this->code($record, 'status', $label, true);
        if (! in_array($status, self::PAYMENT_STATUSES, true)) {
            throw new InvalidFrcRecord($label.': unknown payment status.');
        }
        $receipt = $record['receipt_number'] ?? null;
        if ($receipt !== null && (! is_string($receipt) || mb_strlen($receipt) > 40 || ! preg_match('/^[A-Za-z0-9\/_.-]*$/', $receipt))) {
            throw new InvalidFrcRecord($label.': invalid receipt number.');
        }

        return $this->withHash([
            'frc_payment_id' => $id,
            'receipt_number' => $receipt === '' ? null : $receipt,
            'frc_enrollment_id' => $enrollmentId,
            'frc_branch_id' => is_array($enrollment) ? $this->id($enrollment, 'branch_id', $label.' enrollment') : null,
            'frc_service_id' => is_array($enrollment) ? $this->id($enrollment, 'service_id', $label.' enrollment') : null,
            'amount' => $this->amount($record, 'amount', $label, true),
            'payment_method' => $method,
            'source_status' => $status,
            'enrollment_status' => is_array($enrollment) ? $this->code($enrollment, 'status', $label.' enrollment') : null,
            'payment_date' => $this->date($record, 'payment_date', $label),
            'verified_at' => $this->timestamp($record, 'verified_at', $label),
            'source_created_at' => $this->timestamp($record, 'created_at', $label),
            'source_updated_at' => $this->timestamp($record, 'updated_at', $label, true),
            'source_deleted_at' => $this->timestamp($record, 'deleted_at', $label),
            'has_nested_enrollment' => is_array($enrollment),
        ], self::PAYMENT_HASH_FIELDS);
    }

    /** Best-effort FRC id of an invalid record, for the exception row only. */
    public function externalId(mixed $record): ?int
    {
        return is_array($record) && isset($record['id']) && filter_var($record['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            ? (int) $record['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function withHash(array $values, array $fields): array
    {
        $canonical = [];
        foreach ($fields as $field) {
            $value = $values[$field];
            $canonical[$field] = $value instanceof Carbon ? $value->copy()->utc()->format('Y-m-d\TH:i:s\Z') : $value;
        }
        $values['content_hash'] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $values;
    }

    /** @return array<string, mixed> */
    private function record(mixed $record, string $label): array
    {
        if (! is_array($record) || array_is_list($record)) {
            throw new InvalidFrcRecord($label.' record is not an object.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function id(array $record, string $key, string $label, bool $required = false): ?int
    {
        $value = $record[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidFrcRecord($label.': '.$key.' is required.');
            }

            return null;
        }
        if (is_bool($value) || is_float($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidFrcRecord($label.': '.$key.' must be a positive whole number.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $record */
    private function code(array $record, string $key, string $label, bool $required = false): ?string
    {
        $value = $record[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidFrcRecord($label.': '.$key.' is required.');
            }

            return null;
        }
        if (! is_string($value) || ! preg_match('/^[a-z][a-z_]{0,39}$/', $value)) {
            throw new InvalidFrcRecord($label.': '.$key.' has an invalid format.');
        }

        return $value;
    }

    /**
     * A decimal string (or whole number) with at most two decimals. Floats are
     * refused because they cannot carry an exact amount.
     *
     * @param  array<string, mixed>  $record
     */
    private function amount(array $record, string $key, string $label, bool $required, bool $allowNegative = false): string
    {
        $value = $record[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidFrcRecord($label.': '.$key.' is required.');
            }

            return AccountingDecimal::string(AccountingDecimal::of('0'));
        }
        $pattern = $allowNegative ? '/^-?\d{1,15}(\.\d{1,2})?$/' : '/^\d{1,15}(\.\d{1,2})?$/';
        if (! (is_string($value) || is_int($value)) || ! preg_match($pattern, (string) $value)) {
            throw new InvalidFrcRecord($label.': '.$key.' must be a '.($allowNegative ? '' : 'non-negative ').'decimal with at most two decimals.');
        }

        return AccountingDecimal::string(AccountingDecimal::of((string) $value));
    }

    /** @param array<string, mixed> $record */
    private function date(array $record, string $key, string $label): string
    {
        $value = $record[$key] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            throw new InvalidFrcRecord($label.': '.$key.' must be a date (YYYY-MM-DD).');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false || $date->format('Y-m-d') !== substr($value, 0, 10)) {
            throw new InvalidFrcRecord($label.': '.$key.' is not a valid date.');
        }

        return $date->format('Y-m-d');
    }

    /** @param array<string, mixed> $record */
    private function timestamp(array $record, string $key, string $label, bool $required = false): ?Carbon
    {
        $value = $record[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidFrcRecord($label.': '.$key.' is required.');
            }

            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            throw new InvalidFrcRecord($label.': '.$key.' is not a valid date and time.');
        }
        try {
            // Stored in the application timezone, the zone Eloquent assumes when reading timestamps back.
            return Carbon::parse($value)->setTimezone((string) config('app.timezone'))->startOfSecond();
        } catch (\Throwable) {
            throw new InvalidFrcRecord($label.': '.$key.' is not a valid date and time.');
        }
    }
}
