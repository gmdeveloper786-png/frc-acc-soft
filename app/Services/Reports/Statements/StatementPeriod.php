<?php

namespace App\Services\Reports\Statements;

use App\Models\FiscalYear;
use Illuminate\Support\Carbon;

/**
 * Resolves the reporting period, and the comparative period when one is
 * requested, from the statement filters.
 */
class StatementPeriod
{
    public const COMPARE_PREVIOUS_PERIOD = 'previous_period';

    public const COMPARE_PREVIOUS_YEAR = 'previous_year';

    /**
     * An explicit date range wins over a fiscal year. With neither, the current
     * fiscal year is used. Returns null when no period can be determined.
     *
     * @param  array<string, mixed>  $filters
     * @return array{from: string, to: string, label: string, fiscal_year: FiscalYear|null, comparative: array{from: string, to: string, label: string}|null, comparative_notice: string|null}|null
     */
    public function resolve(array $filters): ?array
    {
        $year = null;
        if (filled($filters['from'] ?? null) && filled($filters['to'] ?? null)) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $to = Carbon::parse($filters['to'])->startOfDay();
        } else {
            $year = filled($filters['fiscal_year_id'] ?? null)
                ? FiscalYear::query()->find($filters['fiscal_year_id'])
                : FiscalYear::query()->where('is_current', true)->orderByDesc('start_date')->first();
            if ($year === null) {
                return null;
            }
            $from = $year->start_date->copy()->startOfDay();
            $to = $year->end_date->copy()->startOfDay();
        }

        $period = [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'label' => ($year ? $year->name.': ' : '').self::rangeLabel($from, $to),
            'fiscal_year' => $year, 'comparative' => null, 'comparative_notice' => null,
        ];

        $compare = $filters['compare'] ?? null;
        if ($compare === self::COMPARE_PREVIOUS_PERIOD) {
            $days = (int) $from->diffInDays($to) + 1;
            $previousTo = $from->copy()->subDay();
            $previousFrom = $previousTo->copy()->subDays($days - 1);
            $period['comparative'] = ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(), 'label' => 'Previous period: '.self::rangeLabel($previousFrom, $previousTo)];
        } elseif ($compare === self::COMPARE_PREVIOUS_YEAR && $year !== null) {
            $previous = FiscalYear::query()->whereDate('end_date', '<', $from->toDateString())->orderByDesc('end_date')->first();
            if ($previous === null) {
                $period['comparative_notice'] = 'No earlier fiscal year is defined, so no comparison is shown.';
            } else {
                $period['comparative'] = ['from' => $previous->start_date->toDateString(), 'to' => $previous->end_date->toDateString(), 'label' => $previous->name.': '.self::rangeLabel($previous->start_date, $previous->end_date)];
            }
        } elseif ($compare === self::COMPARE_PREVIOUS_YEAR) {
            $previousFrom = $from->copy()->subYearNoOverflow();
            $previousTo = $to->copy()->subYearNoOverflow();
            $period['comparative'] = ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(), 'label' => 'Same dates one year earlier: '.self::rangeLabel($previousFrom, $previousTo)];
        }

        return $period;
    }

    public static function rangeLabel(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        return $from->format('d M Y').' to '.$to->format('d M Y');
    }
}
