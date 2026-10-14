<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns an audit entry into text that is safe to show: secrets and personal
 * identifiers are always hidden, and payroll or employee amounts are hidden
 * from viewers without `payroll.view_sensitive`.
 */
class AuditLogPresenter
{
    public const HIDDEN = '[hidden]';

    public const RESTRICTED = '[restricted]';

    /** Keys whose values are never displayed, whoever is looking. */
    private const SECRET_KEYS = ['password', 'token', 'secret', 'app_key', 'remember', 'authorization', 'bank', 'account_number', 'cnic'];

    private const MONEY_KEY = '/amount|salary|salaries|gross|net|total|pay|basic|allowance|deduction|earning|tax|bonus|loan|advance|rate|wage|balance|cost|value|stated/i';

    private const NON_MONEY_KEY = '/(^|_)(id|ids|count|rows|numbers|status|key|type|code|date|by|at|reason|hash)$/i';

    public static function module(string $event): string
    {
        return Str::before($event, '.');
    }

    public static function moduleLabel(string $module): string
    {
        return Str::headline($module);
    }

    /** "Refused" when the system blocked the attempt, otherwise "Recorded". */
    public static function result(string $event): string
    {
        return Str::endsWith($event, AuditLogViewer::REFUSED_SUFFIXES) ? 'Refused' : 'Recorded';
    }

    public static function recordType(?string $type): string
    {
        return $type ? Str::headline(class_basename($type)) : '';
    }

    public function record(AuditLog $log): ?string
    {
        return $log->auditable_type ? self::recordType($log->auditable_type).' #'.$log->auditable_id : null;
    }

    public function userAgent(AuditLog $log, int $limit = 80): ?string
    {
        return $log->user_agent ? Str::limit($log->user_agent, $limit) : null;
    }

    /** Payroll and employee entries carry salary figures. */
    public function isPayrollSensitive(AuditLog $log): bool
    {
        return Str::startsWith($log->event, ['payroll', 'employees.'])
            || Str::contains((string) $log->auditable_type, ['Payroll', 'Employee']);
    }

    /**
     * The properties as a flat, masked key/value list.
     *
     * @return list<array{label: string, value: string, masked: bool}>
     */
    public function properties(AuditLog $log, User $viewer): array
    {
        $maskMoney = $this->isPayrollSensitive($log) && ! $viewer->can('payroll.view_sensitive');
        $rows = [];
        $this->flatten((array) ($log->properties ?? []), [], $maskMoney, false, $rows);

        return $rows;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  list<string>  $path
     * @param  list<array{label: string, value: string, masked: bool}>  $rows
     */
    private function flatten(array $values, array $path, bool $maskMoney, bool $underMoneyKey, array &$rows): void
    {
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $keyPath = [...$path, $key];
            $label = implode(' › ', array_map(fn (string $segment): string => is_numeric($segment) ? '#'.((int) $segment + 1) : Str::headline($segment), $keyPath));

            if (Str::contains(Str::lower($key), self::SECRET_KEYS)) {
                $rows[] = ['label' => $label, 'value' => self::HIDDEN, 'masked' => true];

                continue;
            }
            $moneyKey = $underMoneyKey || (! is_numeric($key) && preg_match(self::MONEY_KEY, $key) === 1 && preg_match(self::NON_MONEY_KEY, $key) !== 1);
            if (is_array($value)) {
                if ($value === []) {
                    $rows[] = ['label' => $label, 'value' => '—', 'masked' => false];
                } elseif (array_is_list($value) && ! $this->hasNested($value)) {
                    $masked = $maskMoney && ($moneyKey || $this->anyMoney($value));
                    $rows[] = ['label' => $label, 'value' => $masked ? self::RESTRICTED : implode(', ', array_map($this->text(...), $value)), 'masked' => $masked];
                } else {
                    $this->flatten($value, $keyPath, $maskMoney, $moneyKey, $rows);
                }

                continue;
            }
            $masked = $maskMoney && ($moneyKey || $this->looksLikeMoney($value));
            $rows[] = ['label' => $label, 'value' => $masked ? self::RESTRICTED : $this->text($value), 'masked' => $masked];
        }
    }

    /** @param array<int, mixed> $values */
    private function hasNested(array $values): bool
    {
        return array_filter($values, 'is_array') !== [];
    }

    /** @param array<int, mixed> $values */
    private function anyMoney(array $values): bool
    {
        return array_filter($values, $this->looksLikeMoney(...)) !== [];
    }

    private function looksLikeMoney(mixed $value): bool
    {
        return is_float($value) || (is_string($value) && preg_match('/^-?\d{1,15}\.\d{1,4}$/', $value) === 1);
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }
}
