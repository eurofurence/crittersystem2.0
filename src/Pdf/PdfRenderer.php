<?php

namespace App\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Renders HTML (or a Twig template) to a PDF byte string via dompdf. Used for
 * the schedule-timeline and staffing-matrix exports. Remote resource loading is
 * disabled so a document can never pull in external URLs.
 */
final class PdfRenderer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function renderHtml(string $html, string $paper = 'A4', string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function renderTemplate(string $template, array $context = [], string $paper = 'A4', string $orientation = 'landscape'): string
    {
        return $this->renderHtml($this->twig->render($template, $context), $paper, $orientation);
    }
}
