<?php

namespace App\Services\Payroll;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Reads the first worksheet of a payroll workbook WITHOUT evaluating any
 * formula. A formula cell yields only the result the spreadsheet application
 * stored with the file, plus flags telling the importer it was computed and
 * whether it relies on a volatile or indirect function.
 */
class PayrollWorkbookReader
{
    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 60;

    /** Functions whose stored result is not reproducible or not traceable to the cells shown. */
    public const UNSAFE_FUNCTIONS = ['RAND', 'RANDBETWEEN', 'RANDARRAY', 'NOW', 'TODAY', 'OFFSET', 'INDIRECT'];

    private const HEADER_SEARCH_ROWS = 25;

    /**
     * @return array{
     *     sheet: string,
     *     header_row: int|null,
     *     data_start_row: int|null,
     *     headers: array<string, string>,
     *     rows: array<int, array<string, array{value: string|null, formula: bool, unsafe: bool}>>
     * }
     */
    public function read(string $path, string $extension): array
    {
        $type = match (strtolower($extension)) {
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            'csv' => 'Csv',
            default => throw new RuntimeException('Unsupported workbook type.'),
        };
        $reader = IOFactory::createReader($type);
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($path);

        try {
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($highestRow > self::MAX_ROWS || $highestColumn > self::MAX_COLUMNS) {
                throw new RuntimeException('The worksheet is too large: at most '.self::MAX_ROWS.' rows and '.self::MAX_COLUMNS.' columns are supported.');
            }

            $rows = [];
            for ($row = 1; $row <= $highestRow; $row++) {
                $cells = [];
                for ($column = 1; $column <= $highestColumn; $column++) {
                    if (! $sheet->cellExists([$column, $row])) {
                        continue;
                    }
                    $cell = $this->readCell($sheet->getCell([$column, $row]));
                    if ($cell['formula'] || $cell['value'] !== null) {
                        $cells[Coordinate::stringFromColumnIndex($column)] = $cell;
                    }
                }
                if ($cells !== []) {
                    $rows[$row] = $cells;
                }
            }

            $headerRow = $this->detectHeaderRow($rows);
            $headerSpan = $headerRow !== null && $this->hasSubHeader($sheet, $headerRow, $rows) ? 2 : 1;
            $headers = $headerRow === null ? [] : $this->headers($sheet, $rows, $headerRow, $headerSpan, $highestColumn);

            return [
                'sheet' => $sheet->getTitle(), 'header_row' => $headerRow,
                'data_start_row' => $headerRow === null ? null : $headerRow + $headerSpan,
                'headers' => $headers, 'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** Whether a formula relies on a volatile or indirect function. String literals are ignored. */
    public function isUnsafeFormula(string $formula): bool
    {
        $withoutStrings = preg_replace('/"[^"]*"/', '""', $formula) ?? $formula;

        return preg_match('/(?<![A-Z0-9_.])('.implode('|', self::UNSAFE_FUNCTIONS).')\s*\(/i', $withoutStrings) === 1;
    }

    /**
     * The stored value of a cell. getCalculatedValue() is deliberately never
     * used: a formula is never executed on the server.
     *
     * @return array{value: string|null, formula: bool, unsafe: bool}
     */
    private function readCell(Cell $cell): array
    {
        $raw = $cell->getValue();
        if ($cell->isFormula()) {
            $unsafe = $this->isUnsafeFormula((string) $raw);

            return ['value' => $unsafe ? null : $this->scalar($cell->getOldCalculatedValue()), 'formula' => true, 'unsafe' => $unsafe];
        }

        return ['value' => $this->scalar($raw), 'formula' => false, 'unsafe' => false];
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_float($value)) {
            // Fixed notation, never scientific; six decimals is enough to detect over-precise amounts.
            $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * The header is the first row holding at least two cells, all of them
     * plain text.
     *
     * @param  array<int, array<string, array{value: string|null, formula: bool, unsafe: bool}>>  $rows
     */
    private function detectHeaderRow(array $rows): ?int
    {
        foreach ($rows as $number => $cells) {
            if ($number > self::HEADER_SEARCH_ROWS) {
                break;
            }
            if (count($cells) >= 2 && $this->isTextOnly($cells)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * A second header row exists when a merged cell spans the header row and
     * the row below it, and that row holds only text.
     *
     * @param  array<int, array<string, array{value: string|null, formula: bool, unsafe: bool}>>  $rows
     */
    private function hasSubHeader(Worksheet $sheet, int $headerRow, array $rows): bool
    {
        if (! isset($rows[$headerRow + 1]) || ! $this->isTextOnly($rows[$headerRow + 1])) {
            return false;
        }
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [[, $startRow], [, $endRow]] = Coordinate::rangeBoundaries($range);
            if ((int) $startRow === $headerRow && (int) $endRow === $headerRow + 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array{value: string|null, formula: bool, unsafe: bool}> $cells */
    private function isTextOnly(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell['formula'] || $cell['value'] === null || is_numeric($cell['value'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, array{value: string|null, formula: bool, unsafe: bool}>>  $rows
     * @return array<string, string>
     */
    private function headers(Worksheet $sheet, array $rows, int $headerRow, int $headerSpan, int $highestColumn): array
    {
        // Text of a horizontally merged group heading applies to every column it covers.
        $groupText = [];
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [[$startColumn, $startRow], [$endColumn, $endRow]] = Coordinate::rangeBoundaries($range);
            if ((int) $startRow !== $headerRow || (int) $endRow !== $headerRow) {
                continue;
            }
            $text = $rows[$headerRow][Coordinate::stringFromColumnIndex((int) $startColumn)]['value'] ?? null;
            for ($column = (int) $startColumn; $column <= (int) $endColumn; $column++) {
                $groupText[Coordinate::stringFromColumnIndex($column)] = $text;
            }
        }

        $headers = [];
        for ($column = 1; $column <= $highestColumn; $column++) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $top = $rows[$headerRow][$letter]['value'] ?? $groupText[$letter] ?? null;
            $sub = $headerSpan === 2 ? ($rows[$headerRow + 1][$letter]['value'] ?? null) : null;
            $label = $sub !== null ? ($top !== null ? $top.' / '.$sub : $sub) : $top;
            $hasData = false;
            foreach ($rows as $number => $cells) {
                if ($number >= $headerRow + $headerSpan && isset($cells[$letter])) {
                    $hasData = true;
                    break;
                }
            }
            if ($label !== null || $hasData) {
                $headers[$letter] = $label ?? 'Column '.$letter;
            }
        }

        return $headers;
    }
}
