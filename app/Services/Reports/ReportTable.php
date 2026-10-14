<?php

namespace App\Services\Reports;

/**
 * A finished report ready for export. Build it from the same data the screen
 * shows, after authorization and branch scoping have already been applied.
 */
final class ReportTable
{
    /**
     * @param  list<array{key: string, label: string, type?: 'text'|'money'|'quantity'|'number'|'percent'}>  $columns
     * @param  list<array<string, mixed>>  $rows  Values keyed by column key. Money and quantity values are exact
     *                                            decimal strings. Optional keys: `_style` (`heading`, `subtotal` or `total`)
     *                                            and `_indent` (hierarchy depth, applied to the first column).
     * @param  array<string, string>  $meta  Filters shown under the title, e.g. `['Branch' => 'Lahore', 'As of' => '31 Jan 2026']`.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $meta = [],
        public readonly ?string $note = null,
    ) {}
}
