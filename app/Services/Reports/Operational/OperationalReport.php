<?php

namespace App\Services\Reports\Operational;

use App\Services\Reports\ReportTable;

/**
 * One finished, already authorized and branch-scoped operational report. The
 * screen and every export are rendered from this same object.
 */
final class OperationalReport
{
    /**
     * @param  list<array{key: string, label: string, type?: 'text'|'money'|'quantity'|'number'|'percent'}>  $columns
     * @param  list<array<string, mixed>>  $rows  Detail rows keyed by column key; decimals are exact strings.
     * @param  list<array<string, mixed>>  $totals  Total rows (each carries `_style`), shown after the detail rows.
     * @param  array<string, string>  $meta  Active filters.
     * @param  list<string>  $warnings  Exceptions the reader must see (e.g. a balance mismatch).
     * @param  list<array{label: string, keys: array<int|string, string>}>  $pdfLayout  Condensed columns for the PDF page. A group
     *                                                                                  with one numeric key keeps its figure; any other group joins its text keys
     *                                                                                  (`key => prefix` adds a prefix). Empty keeps every column.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $meta = [],
        public readonly ?string $note = null,
        public readonly array $warnings = [],
        public readonly ?string $prompt = null,
        public readonly array $pdfLayout = [],
    ) {}

    /**
     * CSV and XLSX carry every column. A PDF page is too narrow for a wide
     * register, so it uses the condensed layout: figures are never truncated.
     */
    public function toTable(string $format = 'csv'): ReportTable
    {
        $note = trim(($this->note ?? '').' '.implode(' ', $this->warnings));
        $rows = [...$this->rows, ...$this->totals];
        if ($format !== 'pdf' || $this->pdfLayout === []) {
            return new ReportTable($this->title, $this->columns, $rows, $this->meta, $note !== '' ? $note : null);
        }

        $types = array_column($this->columns, 'type', 'key');
        $columns = [];
        foreach ($this->pdfLayout as $index => $group) {
            $keys = array_values($group['keys']);
            $numeric = count($keys) === 1 && isset($types[$keys[0]]) ? ['type' => $types[$keys[0]]] : [];
            $columns[] = ['key' => 'c'.$index, 'label' => $group['label'], ...$numeric];
        }
        $condensed = array_map(function (array $row) use ($columns): array {
            $line = array_intersect_key($row, ['_style' => true, '_indent' => true]);
            foreach ($this->pdfLayout as $index => $group) {
                if (isset($columns[$index]['type'])) {
                    $line['c'.$index] = $row[array_values($group['keys'])[0]] ?? '';

                    continue;
                }
                $parts = [];
                foreach ($group['keys'] as $key => $value) {
                    [$field, $prefix] = is_int($key) ? [$value, ''] : [$key, $value];
                    if (($row[$field] ?? '') !== '') {
                        $parts[] = $prefix.$row[$field];
                    }
                }
                $line['c'.$index] = implode(' / ', $parts);
            }

            return $line;
        }, $rows);

        return new ReportTable($this->title, $columns, $condensed, [...$this->meta, 'Layout' => 'Condensed for PDF; the CSV and Excel exports carry every column'], $note !== '' ? $note : null);
    }
}
