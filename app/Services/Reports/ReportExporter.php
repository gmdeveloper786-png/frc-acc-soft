<?php

namespace App\Services\Reports;

use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Support\AppSettings;
use Brick\Math\RoundingMode;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a ReportTable into a CSV, XLSX or PDF download. Files are streamed to
 * the browser and never written to public storage. The caller must already
 * have authorized the user and scoped the data to their branches.
 */
class ReportExporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    private const NUMERIC_TYPES = ['money', 'quantity', 'number', 'percent'];

    public function __construct(private AppSettings $settings, private AuditLogger $audit) {}

    public function download(ReportTable $table, string $format, string $filename): StreamedResponse
    {
        abort_unless(in_array($format, self::FORMATS, true), 404);
        $this->audit->log('report.exported', null, ['report' => $table->title, 'format' => $format, 'rows' => count($table->rows), 'filters' => $table->meta]);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $filename).'-'.now()->format('Ymd-His').'.'.$format;
        $content = match ($format) {
            'csv' => $this->csv($table),
            'xlsx' => $this->xlsx($table),
            'pdf' => $this->pdf($table),
        };
        $type = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'][$format];

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $name, ['Content-Type' => $type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Neutralise spreadsheet formula injection in text taken from user input. */
    public static function safeText(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }

    /** @return list<string> Organisation, title, filters and generation stamp. */
    public function headerLines(ReportTable $table): array
    {
        $lines = [$this->settings->string('organization.name', (string) config('accounting.organization.name')), $table->title];
        foreach ($table->meta as $label => $value) {
            $lines[] = $label.': '.$value;
        }
        $lines[] = 'Currency: '.$this->settings->string('currency.code', (string) config('accounting.currency.code'));
        $lines[] = 'Generated: '.now()->format('d M Y H:i').' by '.(auth()->user()?->name ?? 'system');
        if ($table->note) {
            $lines[] = 'Note: '.$table->note;
        }

        return $lines;
    }

    private function csv(ReportTable $table): string
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($this->headerLines($table) as $line) {
            fputcsv($handle, [self::safeText($line)]);
        }
        fputcsv($handle, []);
        fputcsv($handle, array_column($table->columns, 'label'));
        foreach ($table->rows as $row) {
            fputcsv($handle, array_map(fn (array $column): string => $this->isNumeric($column, $row)
                ? (string) $row[$column['key']]
                : str_repeat('  ', $this->indent($column, $table, $row)).self::safeText($row[$column['key']] ?? ''), $table->columns));
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function xlsx(ReportTable $table): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Report');
        $line = 1;
        foreach ($this->headerLines($table) as $index => $text) {
            $sheet->setCellValueExplicit([1, $line], $text, DataType::TYPE_STRING);
            $sheet->getStyle([1, $line])->getFont()->setBold($index < 2);
            $line++;
        }
        $line++;
        foreach ($table->columns as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, $line], $column['label'], DataType::TYPE_STRING);
            $sheet->getStyle([$index + 1, $line])->getFont()->setBold(true);
        }
        foreach ($table->rows as $row) {
            $line++;
            foreach ($table->columns as $index => $column) {
                $cell = [$index + 1, $line];
                if ($this->isNumeric($column, $row)) {
                    $sheet->setCellValueExplicit($cell, (string) $row[$column['key']], DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(['money' => '#,##0.00', 'quantity' => '#,##0.0000', 'percent' => '0.00'][$column['type']] ?? '0');
                } else {
                    // An explicit string cell is never interpreted as a formula.
                    $sheet->setCellValueExplicit($cell, str_repeat('  ', $this->indent($column, $table, $row)).($row[$column['key']] ?? ''), DataType::TYPE_STRING);
                }
                if (isset($row['_style'])) {
                    $sheet->getStyle($cell)->getFont()->setBold(true);
                }
            }
        }
        foreach (array_keys($table->columns) as $index) {
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = (string) ob_get_clean();
        $book->disconnectWorksheets();

        return $content;
    }

    private function pdf(ReportTable $table): string
    {
        $pdf = new SimplePdfDocument;
        $widths = $this->columnWidths($table, $pdf->usableWidth());
        $header = function () use ($pdf, $table, $widths): void {
            foreach ($this->headerLines($table) as $index => $line) {
                $pdf->text($pdf->fit($line, $pdf->usableWidth(), $index < 2 ? 11 : 8, $index < 2), 0, $index < 2 ? 11 : 8, $index < 2);
                $pdf->moveDown($index < 2 ? 15 : 11);
            }
            $pdf->moveDown(4);
            $this->pdfRow($pdf, $table, $widths, array_column($table->columns, 'label', 'key'), true, true);
            $pdf->rule();
        };
        $header();
        foreach ($table->rows as $row) {
            if ($pdf->needsBreak(12)) {
                $pdf->addPage();
                $header();
            }
            $this->pdfRow($pdf, $table, $widths, $row, isset($row['_style']), false);
        }

        return $pdf->output();
    }

    /**
     * @param  list<float>  $widths
     * @param  array<string, mixed>  $row
     */
    private function pdfRow(SimplePdfDocument $pdf, ReportTable $table, array $widths, array $row, bool $bold, bool $isHeader): void
    {
        $x = 0;
        foreach ($table->columns as $index => $column) {
            $numeric = in_array($column['type'] ?? 'text', self::NUMERIC_TYPES, true);
            $value = $row[$column['key']] ?? '';
            if (! $isHeader && $this->isNumeric($column, $row)) {
                $value = $this->display($column['type'], (string) $value);
            }
            $indent = $isHeader ? 0 : $this->indent($column, $table, $row) * 8;
            $text = $pdf->fit((string) $value, $widths[$index] - 6 - $indent, 8, $bold);
            $numeric ? $pdf->text($text, 0, 8, $bold, $x + $widths[$index] - 4) : $pdf->text($text, $x + $indent, 8, $bold);
            $x += $widths[$index];
        }
        $pdf->moveDown(12);
    }

    /** @return list<float> */
    private function columnWidths(ReportTable $table, float $usable): array
    {
        $weights = array_map(fn (array $column): float => in_array($column['type'] ?? 'text', self::NUMERIC_TYPES, true) ? 1.0 : 2.2, $table->columns);
        $weights[0] = max($weights[0], 2.6);
        $total = array_sum($weights);

        return array_map(fn (float $weight): float => $usable * $weight / $total, $weights);
    }

    /** Exact decimal formatting with thousands separators; no floats involved. */
    private function display(string $type, string $value): string
    {
        $scale = ['money' => 2, 'percent' => 2, 'quantity' => 4][$type] ?? 0;
        $rounded = (string) AccountingDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));

        return (str_starts_with($rounded, '-') ? '-' : '').$whole.($fraction !== '' ? '.'.$fraction : '');
    }

    /** @param array<string, mixed> $row */
    private function isNumeric(array $column, array $row): bool
    {
        $value = $row[$column['key']] ?? null;

        return in_array($column['type'] ?? 'text', self::NUMERIC_TYPES, true) && is_numeric($value);
    }

    /** @param array<string, mixed> $row */
    private function indent(array $column, ReportTable $table, array $row): int
    {
        return $column['key'] === $table->columns[0]['key'] ? (int) ($row['_indent'] ?? 0) : 0;
    }
}
