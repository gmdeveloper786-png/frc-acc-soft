<?php

namespace App\Services\BankReconciliation;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use RuntimeException;

/**
 * Reads the first worksheet of a bank statement (CSV or XLSX) into plain cell
 * text keyed by column letter. Formulas are never evaluated: a formula cell is
 * flagged and carries no value. Cell contents are returned to the importer
 * only; they are never logged or put into exception messages.
 */
class StatementFileReader
{
    public const EXTENSIONS = ['csv', 'xlsx'];

    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 40;

    private const HEADER_SEARCH_ROWS = 25;

    /**
     * @return array{
     *     header_row: int|null,
     *     headers: array<string, string>,
     *     rows: array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>>
     * }
     */
    public function read(string $path, string $extension): array
    {
        $rows = match (strtolower($extension)) {
            'csv' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Unsupported statement file type.'),
        };
        $headerRow = $this->detectHeaderRow($rows);

        return ['header_row' => $headerRow, 'headers' => $headerRow === null ? [] : $this->headers($rows, $headerRow), 'rows' => $rows];
    }

    /** @return array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The statement file could not be opened.');
        }

        try {
            $first = (string) fgets($handle);
            $delimiter = $this->delimiter($first);
            rewind($handle);
            $rows = [];
            $number = 0;
            while (($fields = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                if ($number > self::MAX_ROWS + self::HEADER_SEARCH_ROWS) {
                    throw new RuntimeException('The statement has too many rows.');
                }
                if (count($fields) > self::MAX_COLUMNS) {
                    throw new RuntimeException('The statement has too many columns.');
                }
                $cells = [];
                foreach ($fields as $index => $field) {
                    $text = $number === 1 && $index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $field) : (string) $field;
                    $value = $this->clean($text);
                    if ($value !== null) {
                        $cells[Coordinate::stringFromColumnIndex($index + 1)] = ['value' => $value, 'formula' => false, 'numeric' => false];
                    }
                }
                if ($cells !== []) {
                    $rows[$number] = $cells;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>> */
    private function readXlsx(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);

        try {
            $sheet = $book->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($highestRow > self::MAX_ROWS + self::HEADER_SEARCH_ROWS || $highestColumn > self::MAX_COLUMNS) {
                throw new RuntimeException('The statement worksheet is too large.');
            }
            $rows = [];
            for ($row = 1; $row <= $highestRow; $row++) {
                $cells = [];
                for ($column = 1; $column <= $highestColumn; $column++) {
                    if (! $sheet->cellExists([$column, $row])) {
                        continue;
                    }
                    $cell = $sheet->getCell([$column, $row]);
                    $raw = $cell->getValue();
                    if ($cell->isFormula()) {
                        $cells[Coordinate::stringFromColumnIndex($column)] = ['value' => null, 'formula' => true, 'numeric' => false];

                        continue;
                    }
                    $numeric = is_int($raw) || is_float($raw);
                    $value = $this->scalar($raw);
                    if ($value !== null) {
                        $cells[Coordinate::stringFromColumnIndex($column)] = ['value' => $value, 'formula' => false, 'numeric' => $numeric];
                    }
                }
                if ($cells !== []) {
                    $rows[$row] = $cells;
                }
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_float($value)) {
            // Fixed notation; six decimals is enough to detect over-precise amounts.
            $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }
        if ($value === null || is_bool($value) || ! is_scalar($value)) {
            return null;
        }

        return $this->clean((string) $value);
    }

    private function clean(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * The header is the first row with at least two cells that all hold text.
     *
     * @param  array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>>  $rows
     */
    private function detectHeaderRow(array $rows): ?int
    {
        foreach ($rows as $number => $cells) {
            if ($number > self::HEADER_SEARCH_ROWS) {
                break;
            }
            $textOnly = collect($cells)->every(fn (array $cell): bool => ! $cell['formula'] && ! $cell['numeric'] && $cell['value'] !== null && ! is_numeric($cell['value']));
            if (count($cells) >= 2 && $textOnly) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>>  $rows
     * @return array<string, string>
     */
    private function headers(array $rows, int $headerRow): array
    {
        $letters = [];
        foreach ($rows as $number => $cells) {
            if ($number >= $headerRow) {
                $letters = array_merge($letters, array_keys($cells));
            }
        }
        $letters = array_unique($letters);
        usort($letters, fn (string $a, string $b): int => Coordinate::columnIndexFromString($a) <=> Coordinate::columnIndexFromString($b));

        $headers = [];
        foreach ($letters as $letter) {
            $headers[$letter] = str($rows[$headerRow][$letter]['value'] ?? 'Column '.$letter)->limit(80)->toString();
        }

        return $headers;
    }
}
