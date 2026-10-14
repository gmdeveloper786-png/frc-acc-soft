<?php

namespace App\Services\Reports;

/**
 * Minimal dependency-free PDF writer for tabular reports: A4 landscape,
 * built-in Helvetica, text only. It produces a real PDF file, not a print view.
 */
class SimplePdfDocument
{
    private const PAGE_WIDTH = 842;

    private const PAGE_HEIGHT = 595;

    private const MARGIN = 36;

    /** @var list<string> Content stream of each page. */
    private array $pages = [];

    private string $stream = '';

    private float $cursor = 0;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->stream !== '') {
            $this->pages[] = $this->stream;
        }
        $this->stream = '';
        $this->cursor = self::PAGE_HEIGHT - self::MARGIN;
    }

    public function usableWidth(): float
    {
        return self::PAGE_WIDTH - 2 * self::MARGIN;
    }

    /** True when fewer than the given points remain on the page. */
    public function needsBreak(float $height): bool
    {
        return $this->cursor - $height < self::MARGIN + 14;
    }

    public function moveDown(float $height): void
    {
        $this->cursor -= $height;
    }

    /** Write one line at the given x offset from the left margin. */
    public function text(string $text, float $x = 0, float $size = 9, bool $bold = false, ?float $rightEdge = null): void
    {
        $encoded = $this->encode($text);
        $position = self::MARGIN + $x;
        if ($rightEdge !== null) {
            $position = self::MARGIN + $rightEdge - $this->width($encoded, $size, $bold);
        }
        $this->stream .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $position, $this->cursor, $this->escape($encoded));
    }

    public function rule(): void
    {
        $this->stream .= sprintf("0.5 w %.2F %.2F m %.2F %.2F l S\n", self::MARGIN, $this->cursor + 3, self::PAGE_WIDTH - self::MARGIN, $this->cursor + 3);
    }

    /** Shorten text so it fits the given width. */
    public function fit(string $text, float $width, float $size, bool $bold = false): string
    {
        $encoded = $this->encode($text);
        if ($this->width($encoded, $size, $bold) <= $width) {
            return $text;
        }
        while ($encoded !== '' && $this->width($encoded.'...', $size, $bold) > $width) {
            $encoded = substr($encoded, 0, -1);
        }

        return mb_convert_encoding($encoded, 'UTF-8', 'Windows-1252').'...';
    }

    public function output(): string
    {
        $pages = [...$this->pages, $this->stream];
        $total = count($pages);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        foreach ($pages as $index => $content) {
            $content .= sprintf("BT /F1 7 Tf %.2F %.2F Td (%s) Tj ET\n", self::PAGE_WIDTH - self::MARGIN - 60, self::MARGIN - 14, 'Page '.($index + 1).' of '.$total);
            $pageId = 5 + $index * 2;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $pageId + 1);
            $objects[$pageId + 1] = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($content), $content);
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), $total);
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF';
    }

    /** Approximate Helvetica advance widths; good enough for alignment and truncation. */
    private function width(string $encoded, float $size, bool $bold): float
    {
        $units = 0;
        foreach (str_split($encoded) as $character) {
            $units += match (true) {
                $character === ' ' || str_contains('.,:;!|ijl\'', $character) => 278,
                str_contains('ftr()[]-/', $character) => 333,
                ctype_digit($character) => 556,
                str_contains('mwMW', $character) => 833,
                ctype_upper($character) => 690,
                default => 540,
            };
        }

        return $units / 1000 * $size * ($bold ? 1.06 : 1);
    }

    private function encode(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }

    private function escape(string $encoded): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}
