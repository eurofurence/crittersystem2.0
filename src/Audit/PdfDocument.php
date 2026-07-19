<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * A minimal, dependency-free PDF writer for the human-readable audit export.
 *
 * It lays plain text out across A4 pages using the standard Helvetica font, and
 * emits a structurally valid PDF. This produces a fixed, presentable document
 * for non-technical readers; the machine-readable JSON remains the authoritative
 * forensic artifact. (Strict PDF/A-1b conformance - font embedding, output
 * intent - is intentionally out of scope here.)
 */
final class PdfDocument
{
    private const LINES_PER_PAGE = 52;
    private const FONT_SIZE = 9;
    private const LEADING = 13;
    private const TOP = 800;
    private const LEFT = 48;

    /** @var string[] */
    private array $lines = [];

    public function addLine(string $text = ''): void
    {
        // Wrap long lines crudely at a fixed character width for monospaced flow.
        $chunks = $text === '' ? [''] : str_split($text, 110);
        foreach ($chunks as $chunk) {
            $this->lines[] = $chunk;
        }
    }

    public function render(): string
    {
        $pages = array_chunk($this->lines ?: [''], self::LINES_PER_PAGE);

        $objects = [];
        // 1: catalog, 2: pages tree, 3: font; pages start at object 4.
        $pageObjectIds = [];
        $contentObjectIds = [];
        $next = 4;
        foreach ($pages as $_) {
            $pageObjectIds[] = $next++;
            $contentObjectIds[] = $next++;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageObjectIds));
        $objects[2] = '<< /Type /Pages /Kids ['.$kids.'] /Count '.\count($pages).' >>';

        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach ($pages as $i => $pageLines) {
            $pageId = $pageObjectIds[$i];
            $contentId = $contentObjectIds[$i];
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentId.' 0 R >>';

            $stream = $this->contentStream($pageLines);
            $objects[$contentId] = '<< /Length '.\strlen($stream).' >>'."\nstream\n".$stream."\nendstream";
        }

        return $this->assemble($objects);
    }

    /** @param string[] $pageLines */
    private function contentStream(array $pageLines): string
    {
        $out = "BT\n/F1 ".self::FONT_SIZE." Tf\n".self::LEADING." TL\n1 0 0 1 ".self::LEFT.' '.self::TOP." Tm\n";
        $first = true;
        foreach ($pageLines as $line) {
            if (!$first) {
                $out .= "T*\n";
            }
            $out .= '('.$this->escape($line).") Tj\n";
            $first = false;
        }
        $out .= 'ET';

        return $out;
    }

    private function escape(string $text): string
    {
        // Keep it to printable ASCII; PDF text strings escape \ ( ).
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';

        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** @param array<int, string> $objects */
    private function assemble(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = \strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $count = \count($objects);
        $xrefOffset = \strlen($pdf);
        $pdf .= "xref\n0 ".($count + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $count; ++$id) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($count + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }
}
